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
        // Email-safe image defaults: block display (kills the gap under images),
        // no border/underline when linked, and max-width to prevent overflow.
        $baseStyle = 'display: block; border: 0; outline: none; text-decoration: none; max-width: 100%; height: auto; -ms-interpolation-mode: bicubic;';

        return '<img src="'.$this->src.'" alt="'.$this->alt.'" style="'. $baseStyle . ' ' . $this->style.'" class="image" />';
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