<?php

namespace Zslab\Search\Utils;

class JamoConverter
{
    private const CHOSUNG  = ['ㄱ','ㄲ','ㄴ','ㄷ','ㄸ','ㄹ','ㅁ','ㅂ','ㅃ','ㅅ','ㅆ','ㅇ','ㅈ','ㅉ','ㅊ','ㅋ','ㅌ','ㅍ','ㅎ'];
    private const JUNGSUNG = ['ㅏ','ㅐ','ㅑ','ㅒ','ㅓ','ㅔ','ㅕ','ㅖ','ㅗ','ㅘ','ㅙ','ㅚ','ㅛ','ㅜ','ㅝ','ㅞ','ㅟ','ㅠ','ㅡ','ㅢ','ㅣ'];
    private const JONGSUNG = ['','ㄱ','ㄲ','ㄳ','ㄴ','ㄵ','ㄶ','ㄷ','ㄹ','ㄺ','ㄻ','ㄼ','ㄽ','ㄾ','ㄿ','ㅀ','ㅁ','ㅂ','ㅄ','ㅅ','ㅆ','ㅇ','ㅈ','ㅊ','ㅋ','ㅌ','ㅍ','ㅎ'];

    public static function convert(string $text): string
    {
        $result = '';
        foreach (mb_str_split($text) as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp >= 0xAC00 && $cp <= 0xD7A3) {
                $offset = $cp - 0xAC00;
                $result .= self::CHOSUNG[intdiv($offset, 588)]
                         . self::JUNGSUNG[intdiv($offset % 588, 28)]
                         . self::JONGSUNG[$offset % 28];
            } else {
                $result .= $char;
            }
        }
        return $result;
    }
}
