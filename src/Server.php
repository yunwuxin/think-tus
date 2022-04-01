<?php

namespace think\tus;

use ArrayObject;
use Carbon\Carbon;
use Psr\SimpleCache\CacheInterface;
use Ramsey\Uuid\Uuid;
use think\Cache;
use think\Event;
use think\exception\HttpException;
use think\File;
use think\helper\Arr;
use think\Request;
use think\tus\event\UploadComplete;

class Server
{

    public const TUS_PROTOCOL_VERSION = '1.0.0';

    /** @const string Upload type partial. */
    public const UPLOAD_TYPE_PARTIAL = 'partial';

    /** @const string Upload type final. */
    public const UPLOAD_TYPE_FINAL = 'final';

    /** @const string Upload type normal. */
    protected const UPLOAD_TYPE_NORMAL = 'normal';

    /** @const string Header Content Type */
    protected const HEADER_CONTENT_TYPE = 'application/offset+octet-stream';

    /** @const string Tus Creation Extension */
    public const TUS_EXTENSION_CREATION = 'creation';

    /** @const string Tus Termination Extension */
    public const TUS_EXTENSION_TERMINATION = 'termination';

    /** @const string Tus Checksum Extension */
    public const TUS_EXTENSION_CHECKSUM = 'checksum';

    /** @const string Tus Expiration Extension */
    public const TUS_EXTENSION_EXPIRATION = 'expiration';

    /** @const string Tus Concatenation Extension */
    public const TUS_EXTENSION_CONCATENATION = 'concatenation';

    /** @const array All supported tus extensions */
    public const TUS_EXTENSIONS = [
        self::TUS_EXTENSION_CREATION,
        self::TUS_EXTENSION_CHECKSUM,
        self::TUS_EXTENSION_EXPIRATION,
    ];

    /** @const string Default checksum algorithm */
    private const DEFAULT_CHECKSUM_ALGORITHM = 'sha256';

    protected $maxUploadSize = 0;

    protected $keyName = 'key';

    /** @var string */
    protected $uploadKey;

    /** @var Request */
    protected $request;

    /** @var CacheInterface */
    protected $store;

    /** @var Event */
    protected $event;

    public function __construct(CacheInterface $store, Event $event)
    {
        $this->store = $store;
        $this->event = $event;
    }

    public static function __make(Cache $cache, Event $event)
    {
        return new self($cache->store(), $event);
    }

    /**
     * Get max upload size.
     *
     * @return int
     */
    public function getMaxUploadSize(): int
    {
        return $this->maxUploadSize;
    }

    /**
     * Get upload key from header.
     */
    public function getUploadKey()
    {
        if (!empty($this->uploadKey)) {
            return $this->uploadKey;
        }

        $key = $this->request->header('Upload-Key') ?? Uuid::uuid4()->toString();

        $this->uploadKey = $key;

        return $this->uploadKey;
    }

    public function setKeyName($keyName)
    {
        $this->keyName = $keyName;
        return $this;
    }

    public function serve(Request $request)
    {
        $this->request = $request;

        $requestMethod = $request->method();

        $clientVersion = $request->header('Tus-Resumable');

        if ('OPTIONS' !== $requestMethod && $clientVersion && self::TUS_PROTOCOL_VERSION !== $clientVersion) {
            return response('', 412, [
                'Tus-Version' => self::TUS_PROTOCOL_VERSION,
            ]);
        }

        $method = 'handle' . ucfirst(strtolower($requestMethod));

        return $this->{$method}();
    }

    protected function handleOptions()
    {
        $headers = [
            'Tus-Version'            => self::TUS_PROTOCOL_VERSION,
            'Tus-Extension'          => implode(',', self::TUS_EXTENSIONS),
            'Tus-Checksum-Algorithm' => $this->getSupportedHashAlgorithms(),
        ];

        $maxUploadSize = $this->getMaxUploadSize();

        if ($maxUploadSize > 0) {
            $headers['Tus-Max-Size'] = $maxUploadSize;
        }

        return response('', 200, $headers);
    }

    protected function handleHead()
    {
        $key = $this->request->param($this->keyName);

        $accumulator = $this->getAccumulator($key);

        $headers = [
            'Upload-Length' => (int) $accumulator['size'],
            'Upload-Offset' => (int) $accumulator['offset'],
            'Cache-Control' => 'no-store',
        ];

        return response('', 200, $headers);
    }

    protected function handlePost()
    {
        $this->verifyUploadSize();

        $uploadKey = $this->getUploadKey();

        $checksum = $this->getClientChecksum();
        $location = $this->request->url(true) . '/' . $uploadKey;

        $expire = Carbon::now()->addDay();

        $this->createAccumulator(
            $uploadKey,
            $this->request->header('Upload-Length'),
            $this->getMetadata(),
            $checksum,
            $expire
        );

        $headers = [
            'Location'       => $location,
            'Upload-Expires' => $expire->toRfc7231String(),
        ];

        return response('', 201, $headers);
    }

    protected function handlePatch()
    {
        $key = $this->request->param($this->keyName);

        $accumulator = $this->getAccumulator($key);

        $this->verifyPatchRequest($accumulator);

        $checksum = $accumulator['checksum'];

        try {
            $content = $this->request->getContent();

            file_put_contents($accumulator['path'], $content, FILE_APPEND | LOCK_EX);

            $accumulator['offset'] += strlen($content);

            $this->saveAccumulator($accumulator);

            if ($accumulator['offset'] > $accumulator['size']) {
                throw new HttpException(416);
            }

            // If upload is done, verify checksum.
            if ($accumulator['offset'] === $accumulator['size']) {
                $this->verifyChecksum($checksum, $accumulator['path']);
                //上传成功
                $this->event->trigger(new UploadComplete(new File($accumulator['path']), $accumulator['metadata']));
            }
        } catch (\Exception $e) {
            throw new HttpException(422);
        }

        return response('', 204, [
            'Content-Type'   => self::HEADER_CONTENT_TYPE,
            'Upload-Expires' => $accumulator['expire']->toRfc7231String(),
            'Upload-Offset'  => $accumulator['offset'],
        ]);
    }

    protected function getAccumulator($key)
    {
        if (!$receiver = $this->store->get("tus:{$key}")) {
            throw new HttpException(404);
        }

        return $receiver;
    }

    protected function saveAccumulator($accumulator)
    {
        $this->store->set("tus:{$accumulator['key']}", $accumulator, $accumulator['expire']);
    }

    protected function createAccumulator($key, $size, $metadata, $checksum, $expire)
    {
        $context = new ArrayObject([
            'key'      => $key,
            'path'     => tempnam(sys_get_temp_dir(), "think-tus-{$key}-"),
            'size'     => $size,
            'metadata' => $metadata,
            'checksum' => $checksum,
            'expire'   => $expire,
        ]);

        $this->store->set("tus:{$key}", $context, $expire);

        return $context;
    }

    /**
     * Verify PATCH request.
     *
     * @param ArrayObject $accumulator
     *
     */
    protected function verifyPatchRequest($accumulator)
    {
        $uploadOffset = $this->request->header('upload-offset');

        if ($uploadOffset && $uploadOffset !== (string) $accumulator['offset']) {
            throw new HttpException(409);
        }

        $contentType = $this->request->header('Content-Type');

        if ($contentType !== self::HEADER_CONTENT_TYPE) {
            throw new HttpException(415);
        }
    }

    protected function getMetadata($name = null, $default = null)
    {
        $uploadMetaData = $this->request->header('Upload-Metadata');

        $result = [];

        if (!empty($uploadMetaData)) {
            $uploadMetaDataChunks = explode(',', $uploadMetaData);

            foreach ($uploadMetaDataChunks as $chunk) {
                $pieces = explode(' ', trim($chunk));

                $key   = $pieces[0];
                $value = $pieces[1] ?? '';

                $result[$key] = base64_decode($value);
            }
        }

        return Arr::get($result, $name, $default);
    }

    /**
     * Verify and get upload checksum from header.
     *
     * @return string
     */
    protected function getClientChecksum()
    {
        $checksumHeader = $this->request->header('Upload-Checksum');

        if (empty($checksumHeader)) {
            return '';
        }

        [$checksumAlgorithm, $checksum] = explode(' ', $checksumHeader);

        $checksum = base64_decode($checksum);

        if (false === $checksum || !\in_array($checksumAlgorithm, hash_algos(), true)) {
            throw new HttpException(400);
        }

        return $checksum;
    }

    /**
     * Get list of supported hash algorithms.
     *
     * @return string
     */
    protected function getSupportedHashAlgorithms(): string
    {
        $supportedAlgorithms = hash_algos();

        $algorithms = [];
        foreach ($supportedAlgorithms as $hashAlgo) {
            if (false !== strpos($hashAlgo, ',')) {
                $algorithms[] = "'{$hashAlgo}'";
            } else {
                $algorithms[] = $hashAlgo;
            }
        }

        return implode(',', $algorithms);
    }

    /**
     * Verify max upload size.
     */
    protected function verifyUploadSize(): bool
    {
        $maxUploadSize = $this->getMaxUploadSize();

        if ($maxUploadSize > 0 && $this->request->header('Upload-Length') > $maxUploadSize) {
            throw new HttpException(413);
        }
    }

    /**
     * Verify checksum if available.
     *
     * @param string $checksum
     * @param string $filePath
     */
    protected function verifyChecksum(string $checksum, string $filePath)
    {
        if (!empty($checksum) && $checksum != $this->getServerChecksum($filePath)) {
            throw new HttpException(460);
        }
    }

    /**
     * Get file checksum.
     *
     * @param string $filePath
     *
     * @return string
     */
    protected function getServerChecksum(string $filePath): string
    {
        return hash_file($this->getChecksumAlgorithm(), $filePath);
    }

    /**
     * Get checksum algorithm.
     *
     * @return string|null
     */
    protected function getChecksumAlgorithm(): ?string
    {
        $checksumHeader = $this->request->header('Upload-Checksum');

        if (empty($checksumHeader)) {
            return self::DEFAULT_CHECKSUM_ALGORITHM;
        }

        [$checksumAlgorithm, /* $checksum */] = explode(' ', $checksumHeader);

        return $checksumAlgorithm;
    }
}
