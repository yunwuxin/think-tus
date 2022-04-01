<?php

namespace think\tus\event;

use think\File;

class UploadComplete
{
    public $file;

    public $metadata;

    public function __construct(File $file, $metadata)
    {
        $this->file     = $file;
        $this->metadata = $metadata;
    }
}
