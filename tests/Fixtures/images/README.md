# Image fixtures for `MetadataStripperTest`

All files are **synthetic**: rendered with PHP GD (a gradient with an ellipse) and assembled with
`tests/Support/ImageFactory.php`. They contain no real photos and no real personal data. The metadata imitates what
phones and cameras write, and every piece of it contains the marker `SECRET-METADATA`, so tests can check it is gone.
They are committed so the bytes stay stable across GD versions; keep each file under 20 KB.

| File | Container content |
|---|---|
| `camera-gps-orientation6.jpg` | JFIF; APP1 Exif (II, IFD0 make/model/date/artist, Orientation 6, GPS IFD, IFD1 with a JPEG thumbnail); APP1 XMP (GPS, creator); APP2 MPF; APP2 ICC_PROFILE; APP13 Photoshop/IPTC (by-line, city); APP14 Adobe; COM before SOS; a second JPEG after EOI (MPF / motion-photo style) |
| `camera-gps.png` | sRGB, gAMA (and GD's pHYs); eXIf (MM, GPS, no Orientation), tEXt, zTXt, iTXt (XMP) before IDAT; tIME before IEND |
| `camera-gps-orientation6.webp` | VP8X (ICC, EXIF, XMP flags), ICCP, VP8, EXIF (Orientation 6, GPS), `XMP ` |
| `tiny.avif` | 8×8 AVIF from `imageavif(F::canvas(8, 8))` (no metadata); CI's GD may lack libavif, so it is committed |
| `comment-xmp.gif` | GIF89a, NETSCAPE2.0 looping, Comment extension, `XMP DataXMP` application extension |

Generated with (`use Maxcxam\LarsadminMedialibrary\Tests\Support\ImageFactory as F;`):

```php
F::withJpeg(F::jpeg(120, 80),
    [F::exifSegment(F::cameraExif(6)), F::xmpSegment(), F::mpfSegment(), F::iccSegment(), F::iptcSegment(), F::adobeSegment()],
    [F::commentSegment()], [], F::jpeg(30, 20));

F::withPng(F::png(60, 40),
    [F::chunk('sRGB', "\0"), F::chunk('gAMA', pack('N', 45455))],
    [F::chunk('eXIf', F::cameraExif(null, false)), F::chunk('tEXt', "Author\0Jane Doe ".F::SECRET),
        F::chunk('zTXt', "Comment\0\0".gzcompress(F::SECRET)), F::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".F::xmp())],
    [F::chunk('tIME', "\x07\xEA\x0A\x1F\x0C\x22\x38")]);

F::webpExtended(F::webp(60, 40), 60, 40, [F::webpChunk('ICCP', F::iccProfile())],
    [F::webpChunk('EXIF', F::cameraExif(6, true, false)), F::webpChunk('XMP ', F::xmp())]);

F::withGif(F::gif(40, 30),
    [F::gifLooping(), F::gifExtension(0xFE, 'comment '.F::SECRET), F::gifApplication('XMP DataXMP', F::xmp())]);
```
