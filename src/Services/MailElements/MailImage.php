<?php
namespace Condoedge\Communications\Services\MailElements;

use Condoedge\Communications\Services\MailElements\MailElement;
use Illuminate\Support\Facades\Storage;

class MailImage extends MailElement
{
    protected $src;
    protected $alt;

    public function __construct($alt = '')
    {
        $this->alt = $alt;
    }

    public function htmlStructure()
    {
        return '<img src="'.$this->src.'" alt="'.$this->alt.'" style="'. $this->style.'" class="image" />';
    }

    public function src($src)
    {
        $this->src = $src;

        return $this;
    }

    public function srcFromFile($file)
    {
        $this->src = Storage::url(thumb($file->path));
        
        return $this;
    }

    public function alt($alt)
    {
        $this->alt = $alt;

        return $this;
    }
}