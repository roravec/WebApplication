<?php
namespace Panel;

/**
 * Minimal ZIP writer in pure PHP (no ZipArchive extension needed on the hosting): files are compressed with deflate
 * (gzdeflate, zlib) or stored when zlib is missing. File names in UTF-8 (general purpose flag bit 11), so Slovak
 * characters show correctly in Windows / macOS. Enough for packages of a few MB built in memory.
 */
class Zip
{
    /** @var string */ private $data = '';
    /** @var string */ private $central = '';
    /** @var int */ private $count = 0;

    public function add(string $name, string $bytes, ?int $time = null): void
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        list($dosTime, $dosDate) = self::dosTime($time ?? time());
        $crc = crc32($bytes);
        $method = 0;
        $packed = $bytes;
        if (function_exists('gzdeflate')) {
            $z = gzdeflate($bytes, 6);
            if ($z !== false && strlen($z) < strlen($bytes)) {
                $method = 8;
                $packed = $z;
            }
        }
        $flags = 0x0800;   // UTF-8 names
        $offset = strlen($this->data);
        $this->data .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($packed), strlen($bytes),
            strlen($name), 0) . $name . $packed;
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($packed),
            strlen($bytes), strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function bytes(): string
    {
        return $this->data . $this->central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->central), strlen($this->data), 0);
    }

    private static function dosTime(int $t): array
    {
        $d = getdate($t);
        if ($d['year'] < 1980) {
            $d = getdate(mktime(0, 0, 0, 1, 1, 1980));
        }
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
                (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
