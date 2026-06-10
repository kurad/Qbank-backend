<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

class WatermarkedFpdi extends Fpdi
{
    protected float $angle = 0;

    public function rotate(float $angle, float $x = -1, float $y = -1): void
    {
        if ($x === -1) {
            $x = $this->x;
        }

        if ($y === -1) {
            $y = $this->y;
        }

        if ($this->angle != 0) {
            $this->_out('Q');
        }

        $this->angle = $angle;

        if ($angle != 0) {
            $angle *= M_PI / 180;

            $c = cos($angle);
            $s = sin($angle);

            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;

            $this->_out(sprintf(
                'q %.5F %.5F %.5F %.5F %.5F %.5F cm 1 0 0 1 %.5F %.5F cm',
                $c,
                $s,
                -$s,
                $c,
                $cx,
                $cy,
                -$cx,
                -$cy
            ));
        }
    }

    public function rotatedText(float $x, float $y, string $text, float $angle): void
    {
        $this->rotate($angle, $x, $y);
        $this->Text($x, $y, $text);
        $this->rotate(0);
    }

    public function _endpage(): void
    {
        if ($this->angle != 0) {
            $this->angle = 0;
            $this->_out('Q');
        }

        parent::_endpage();
    }
}