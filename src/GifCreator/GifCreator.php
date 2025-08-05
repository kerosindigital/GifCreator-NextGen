<?php

namespace GifCreatorNextGen;

/**
 * Exception for GifCreator-NextGen errors.
 */
class GifCreatorNextGenException extends \Exception
{
}

/**
 * GifCreator-NextGen
 *
 * Creates animated GIFs from multiple frames (resources, file paths, URLs, or binary GIF strings).
 * Supports per-frame durations, loop count, and transparency.
 *
 * This is the "NextGen" fork of Sybio/GifCreator, modernized for PHP 8+.
 *
 * @author Clément Guillemain (Sybio), forked by kerosindigital
 * @link https://github.com/kerosindigital/gifcreator-nextgen
 * @version 2.0
 */
class GifCreatorNextGen
{
    // --- Constants for GIF structure ---
    private const GIF_HEADER_SIZE = 6;
    private const GIF_LSD_SIZE = 7;
    private const GIF_GCT_FLAG = 0x80;
    private const GIF_GCT_SIZE_MASK = 0x07;
    private const GIF_IMAGE_DESCRIPTOR_SIZE = 10;
    private const GIF_EXTENSION_INTRODUCER = '!';
    private const GIF_IMAGE_SEPARATOR = ',';
    private const DEFAULT_DISPOSAL_METHOD = 2; // Restore to background
    private const DEFAULT_TRANSPARENT_COLOR = -1;

    // --- Internal state ---
    private string $gif = '';
    private bool $imgBuilt = false;
    private array $frameSources = [];
    private int $loop = 0;
    private int $disposalMethod = self::DEFAULT_DISPOSAL_METHOD;
    private int $transparentColor = self::DEFAULT_TRANSPARENT_COLOR;

    private array $errors = [
        'ERR00' => 'Input must be arrays for frames and durations.',
        'ERR01' => 'Source is not a valid GIF image.',
        'ERR02' => 'Frame must be a GD resource, valid file/URL path, or GIF binary string.',
        'ERR03' => 'Animated GIFs as source frames are not supported.',
        'ERR04' => 'Failed to read frame data from source.',
        'ERR05' => 'Failed to create image from string.',
    ];

    /**
     * Constructor: Initializes/reset the internal state.
     */
    public function __construct()
    {
        $this->reset();
    }

    /**
     * Creates the GIF animation from frames.
     *
     * @param array $frames Array of image resources, file paths, URLs, or binary GIF strings.
     * @param array $durations Array of per-frame delays in hundredths of a second. Optional.
     * @param int $loop Number of loops (0 = infinite).
     * @return string GIF binary data
     * @throws GifCreatorNextGenException
     */
    public function create(array $frames = [], array $durations = [], int $loop = 0): string
    {
        if (!is_array($frames) || !is_array($durations)) {
            throw new GifCreatorNextGenException($this->errors['ERR00']);
        }

        $this->reset();
        $this->loop = max(0, $loop);
        $this->disposalMethod = self::DEFAULT_DISPOSAL_METHOD;

        foreach ($frames as $i => $frame) {
            $resourceImg = null;
            // Handle GD image resource
            if (is_resource($frame) && get_resource_type($frame) === 'gd') {
                $resourceImg = $frame;
                ob_start();
                imagegif($resourceImg);
                $binaryGif = ob_get_clean();
                $this->frameSources[] = $binaryGif;
                imagedestroy($resourceImg);
            }
            // Handle string input (file path, URL, or binary GIF)
            elseif (is_string($frame)) {
                $frameData = $frame;
                if (file_exists($frame)) {
                    $frameData = @file_get_contents($frame);
                    if ($frameData === false) {
                        throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR04'] . " Path: $frame");
                    }
                } elseif (filter_var($frame, FILTER_VALIDATE_URL)) {
                    $frameData = @file_get_contents($frame, false, stream_context_create(['http' => ['timeout' => 5]]));
                    if ($frameData === false) {
                        throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR04'] . " URL: $frame");
                    }
                }
                $resourceImg = @imagecreatefromstring($frameData);
                if ($resourceImg === false) {
                    throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR05']);
                }
                ob_start();
                imagegif($resourceImg);
                $binaryGif = ob_get_clean();
                $this->frameSources[] = $binaryGif;
                imagedestroy($resourceImg);
            }
            // Invalid frame type
            else {
                throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR02']);
            }

            // Transparent color detection (first frame only)
            if ($i === 0 && isset($resourceImg)) {
                $colorIndex = imagecolortransparent($resourceImg);
                if ($colorIndex !== -1) {
                    $this->transparentColor = $colorIndex;
                }
            }

            // Check GIF header
            $header = substr($this->frameSources[$i], 0, self::GIF_HEADER_SIZE);
            if ($header !== 'GIF87a' && $header !== 'GIF89a') {
                throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR01']);
            }

            // Check for animated GIF (NETSCAPE extension, unsupported as input)
            $gctFlag = ord($this->frameSources[$i][10]) & self::GIF_GCT_FLAG;
            $gctSize = 3 * (2 << (ord($this->frameSources[$i][10]) & self::GIF_GCT_SIZE_MASK));
            $offset = self::GIF_HEADER_SIZE + self::GIF_LSD_SIZE + $gctSize;
            $k = true;
            for ($j = $offset; $j < strlen($this->frameSources[$i]) && $k; $j++) {
                switch ($this->frameSources[$i][$j]) {
                    case self::GIF_EXTENSION_INTRODUCER:
                        if (substr($this->frameSources[$i], ($j + 3), 8) === 'NETSCAPE') {
                            throw new GifCreatorNextGenException("Frame $i: " . $this->errors['ERR03']);
                        }
                        break;
                    case ';':
                        $k = false;
                        break;
                }
            }
        }

        // Build GIF
        $this->addGifHeader();
        foreach ($this->frameSources as $i => $_) {
            $delay = $durations[$i] ?? 0;
            $this->addGifFrame($i, $delay);
        }
        $this->addGifFooter();

        return $this->gif;
    }

    /**
     * Adds the GIF header and global color table, plus NETSCAPE loop extension.
     */
    private function addGifHeader(): void
    {
        $gctFlag = ord($this->frameSources[0][10]) & self::GIF_GCT_FLAG;
        if ($gctFlag) {
            $gctSize = 3 * (2 << (ord($this->frameSources[0][10]) & self::GIF_GCT_SIZE_MASK));
            $this->gif .= substr($this->frameSources[0], self::GIF_HEADER_SIZE, self::GIF_LSD_SIZE);
            $this->gif .= substr($this->frameSources[0], self::GIF_HEADER_SIZE + self::GIF_LSD_SIZE, $gctSize);
            $this->gif .= "!\377\13NETSCAPE2.0\3\1" . $this->encodeLoopCount($this->loop) . "\0";
        }
    }

    /**
     * Adds one frame to the GIF with delay and disposal method.
     *
     * @param int $i Frame index
     * @param int $delay Delay in hundredths of a second
     */
    private function addGifFrame(int $i, int $delay): void
    {
        $gctSize = 3 * (2 << (ord($this->frameSources[$i][10]) & self::GIF_GCT_SIZE_MASK));
        $localTableOffset = self::GIF_HEADER_SIZE + self::GIF_LSD_SIZE + $gctSize;
        $localDataLength = strlen($this->frameSources[$i]) - $localTableOffset - 1;
        $localData = substr($this->frameSources[$i], $localTableOffset, $localDataLength);

        $globalColors = substr($this->frameSources[0], self::GIF_HEADER_SIZE + self::GIF_LSD_SIZE, $gctSize);
        $localColors = substr($this->frameSources[$i], self::GIF_HEADER_SIZE + self::GIF_LSD_SIZE, $gctSize);
        $globalLen = 2 << (ord($this->frameSources[0][10]) & self::GIF_GCT_SIZE_MASK);
        $localLen = 2 << (ord($this->frameSources[$i][10]) & self::GIF_GCT_SIZE_MASK);

        // Graphics control extension (delay/disposal/transparency)
        $gce = "!\xF9\x04" . chr(($this->disposalMethod << 2) + 0) . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF) . "\x0\x0";
        if ($this->transparentColor > -1 && (ord($this->frameSources[$i][10]) & self::GIF_GCT_FLAG)) {
            for ($j = 0; $j < $localLen; $j++) {
                $rgbOffset = 3 * $j;
                if (
                    ord($localColors[$rgbOffset + 0]) === (($this->transparentColor >> 16) & 0xFF) &&
                    ord($localColors[$rgbOffset + 1]) === (($this->transparentColor >> 8) & 0xFF) &&
                    ord($localColors[$rgbOffset + 2]) === (($this->transparentColor >> 0) & 0xFF)
                ) {
                    $gce = "!\xF9\x04" . chr(($this->disposalMethod << 2) + 1) . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF) . chr($j) . "\x0";
                    break;
                }
            }
        }

        // Image descriptor block
        $localImgDescriptor = '';
        if (isset($localData[0])) {
            switch ($localData[0]) {
                case self::GIF_EXTENSION_INTRODUCER: // Extension block
                    $localImgDescriptor = substr($localData, 8, self::GIF_IMAGE_DESCRIPTOR_SIZE);
                    $localData = substr($localData, 18);
                    break;
                case self::GIF_IMAGE_SEPARATOR: // Image descriptor block
                    $localImgDescriptor = substr($localData, 0, self::GIF_IMAGE_DESCRIPTOR_SIZE);
                    $localData = substr($localData, self::GIF_IMAGE_DESCRIPTOR_SIZE);
                    break;
            }
        }

        // Color table handling
        if ((ord($this->frameSources[$i][10]) & self::GIF_GCT_FLAG) && $this->imgBuilt) {
            if ($globalLen === $localLen) {
                if ($this->compareColorTables($globalColors, $localColors, $globalLen)) {
                    $this->gif .= $gce . $localImgDescriptor . $localData;
                } else {
                    $byte = ord($localImgDescriptor[9]);
                    $byte |= self::GIF_GCT_FLAG;
                    $byte &= 0xF8;
                    $byte |= (ord($this->frameSources[0][10]) & self::GIF_GCT_SIZE_MASK);
                    $localImgDescriptor[9] = chr($byte);
                    $this->gif .= $gce . $localImgDescriptor . $localColors . $localData;
                }
            } else {
                $byte = ord($localImgDescriptor[9]);
                $byte |= self::GIF_GCT_FLAG;
                $byte &= 0xF8;
                $byte |= (ord($this->frameSources[$i][10]) & self::GIF_GCT_SIZE_MASK);
                $localImgDescriptor[9] = chr($byte);
                $this->gif .= $gce . $localImgDescriptor . $localColors . $localData;
            }
        } else {
            $this->gif .= $gce . $localImgDescriptor . $localData;
        }

        $this->imgBuilt = true;
    }

    /**
     * Adds the GIF file terminator.
     */
    private function addGifFooter(): void
    {
        $this->gif .= ';';
    }

    /**
     * Compares two color tables for equality.
     *
     * @param string $globalBlock
     * @param string $localBlock
     * @param int $length
     * @return bool
     */
    private function compareColorTables(string $globalBlock, string $localBlock, int $length): bool
    {
        for ($i = 0; $i < $length; $i++) {
            if (
                $globalBlock[3 * $i + 0] !== $localBlock[3 * $i + 0] ||
                $globalBlock[3 * $i + 1] !== $localBlock[3 * $i + 1] ||
                $globalBlock[3 * $i + 2] !== $localBlock[3 * $i + 2]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Encodes integer loop count as ASCII for NETSCAPE extension.
     *
     * @param int $count
     * @return string
     */
    private function encodeLoopCount(int $count): string
    {
        return chr($count & 0xFF) . chr(($count >> 8) & 0xFF);
    }

    /**
     * Resets the internal state for a new GIF.
     */
    public function reset(): void
    {
        $this->frameSources = [];
        $this->gif = 'GIF89a';
        $this->imgBuilt = false;
        $this->loop = 0;
        $this->disposalMethod = self::DEFAULT_DISPOSAL_METHOD;
        $this->transparentColor = self::DEFAULT_TRANSPARENT_COLOR;
    }

    /**
     * Gets the binary GIF data after creation.
     * @return string
     */
    public function getGif(): string
    {
        return $this->gif;
    }
}