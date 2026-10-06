<?php

namespace App\Support;

/**
 * Logo JetCar para documentos impressos: carro e "A" em vermelho, demais letras em grafite
 * (a versão do painel usa branco, que some no papel).
 */
final class BrandLogo
{
    private const RED = '#d90429';

    private const INK = '#111111';

    public static function dataUri(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg());
    }

    private static function svg(): string
    {
        $red = self::RED;
        $ink = self::INK;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="110 65 780 340">
  <g fill="{$red}">
    <path d="M 270,165 C 310,100 370,75 500,75 C 630,75 690,100 730,165 C 650,128 580,118 500,118 C 420,118 350,128 270,165 Z"/>
    <path d="M 258,158 C 218,148 202,165 212,185 C 232,190 248,176 268,168 Z"/>
    <path d="M 742,158 C 782,148 798,165 788,185 C 768,190 752,176 732,168 Z"/>
    <path d="M 195,220 C 290,175 410,158 500,158 C 590,158 710,175 805,220 C 710,192 590,178 500,178 C 410,178 290,192 195,220 Z"/>
    <path d="M 220,235 C 320,202 420,194 500,194 C 580,194 680,202 780,235 C 680,212 580,202 500,202 C 420,202 320,212 220,235 Z"/>
    <path d="M 550,212 C 640,202 745,215 805,250 C 760,215 660,208 550,225 Z"/>
    <path d="M 570,228 C 650,222 735,236 775,268 C 730,236 630,228 540,242 Z"/>
  </g>
  <g transform="translate(0, 10)">
    <g fill="{$ink}">
      <path d="M 120,300 L 220,300 L 220,318 L 180,318 L 180,370 C 180,380 172,388 160,388 L 120,388 L 120,370 L 158,370 L 158,318 L 120,318 Z"/>
      <path d="M 235,300 L 325,300 L 325,318 L 258,318 L 258,335 L 315,335 L 315,353 L 258,353 L 258,370 L 325,370 L 325,388 L 235,388 Z"/>
      <path d="M 340,300 L 440,300 L 440,318 L 400,318 L 400,388 L 380,388 L 380,318 L 340,318 Z"/>
      <path d="M 550,300 L 640,300 L 640,318 L 573,318 L 573,370 L 640,370 L 640,388 L 550,388 Z"/>
      <path fill-rule="evenodd" d="M 770,300 L 860,300 L 860,350 L 825,350 L 860,388 L 832,388 L 800,350 L 793,350 L 793,388 L 770,388 Z M 793,318 L 835,318 C 840,318 842,322 842,326 L 842,328 C 842,332 840,335 835,335 L 793,335 Z"/>
    </g>
    <path fill="{$red}" fill-rule="evenodd" d="M 655,300 L 755,300 L 755,388 L 732,388 L 732,358 L 678,358 L 678,388 L 655,388 Z M 678,318 L 678,340 L 732,340 L 732,318 Z"/>
  </g>
</svg>
SVG;
    }
}
