<?php

declare(strict_types=1);

namespace App\Support;

function penyebut(int $nilai): string
{
    $nilai = abs($nilai);
    $huruf = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    if ($nilai < 12) return ' ' . $huruf[$nilai];
    if ($nilai < 20) return penyebut($nilai - 10) . ' belas';
    if ($nilai < 100) return penyebut((int) floor($nilai / 10)) . ' puluh' . penyebut($nilai % 10);
    if ($nilai < 200) return ' seratus' . penyebut($nilai - 100);
    if ($nilai < 1000) return penyebut((int) floor($nilai / 100)) . ' ratus' . penyebut($nilai % 100);
    if ($nilai < 2000) return ' seribu' . penyebut($nilai - 1000);
    if ($nilai < 1000000) return penyebut((int) floor($nilai / 1000)) . ' ribu' . penyebut($nilai % 1000);
    if ($nilai < 1000000000) return penyebut((int) floor($nilai / 1000000)) . ' juta' . penyebut($nilai % 1000000);
    if ($nilai < 1000000000000) return penyebut((int) floor($nilai / 1000000000)) . ' miliar' . penyebut($nilai % 1000000000);

    return (string) $nilai;
}

function terbilang(int|float $nilai): string
{
    return ucwords(trim(penyebut((int) $nilai))) . ' Rupiah';
}
