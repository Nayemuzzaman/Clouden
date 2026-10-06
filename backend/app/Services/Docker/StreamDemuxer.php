<?php

namespace App\Services\Docker;

/**
 * Decodes Docker's multiplexed stdout/stderr stream format (used by container
 * logs and exec output when no TTY is attached). Each frame is an 8-byte header
 * [stream, 0, 0, 0, size(uint32 big-endian)] followed by the payload.
 */
final class StreamDemuxer
{
    /** @return list<array{stream: string, data: string}> */
    public static function frames(string $raw): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($raw);

        // Containers started with a TTY produce a raw stream without headers.
        if ($length >= 8 && ! in_array(ord($raw[0]), [0, 1, 2], true)) {
            return [['stream' => 'stdout', 'data' => $raw]];
        }

        while ($offset + 8 <= $length) {
            $type = ord($raw[$offset]);
            $size = unpack('N', substr($raw, $offset + 4, 4))[1];
            $data = substr($raw, $offset + 8, $size);
            $frames[] = ['stream' => $type === 2 ? 'stderr' : 'stdout', 'data' => $data];
            $offset += 8 + $size;
        }

        if ($offset === 0 && $length > 0 && $frames === []) {
            $frames[] = ['stream' => 'stdout', 'data' => $raw];
        }

        return $frames;
    }

    /** Concatenate all frames into plain text. */
    public static function text(string $raw): string
    {
        return implode('', array_column(self::frames($raw), 'data'));
    }

    /**
     * Split a demultiplexed stream into lines, preserving which stream each came from.
     *
     * @return list<array{stream: string, line: string}>
     */
    public static function lines(string $raw): array
    {
        $lines = [];
        $pending = ['stdout' => '', 'stderr' => ''];
        foreach (self::frames($raw) as $frame) {
            $pending[$frame['stream']] .= $frame['data'];
            while (($pos = strpos($pending[$frame['stream']], "\n")) !== false) {
                $lines[] = ['stream' => $frame['stream'], 'line' => rtrim(substr($pending[$frame['stream']], 0, $pos), "\r")];
                $pending[$frame['stream']] = substr($pending[$frame['stream']], $pos + 1);
            }
        }
        foreach ($pending as $stream => $rest) {
            if ($rest !== '') {
                $lines[] = ['stream' => $stream, 'line' => rtrim($rest, "\r")];
            }
        }

        return $lines;
    }
}
