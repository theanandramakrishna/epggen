<?php
declare(strict_types=1);

/**
 * /Users/anand/epggen/epggen.php
 * Fetch and output remote gzipped XML
 */

/* Utility: detect and decompress gzip or zip data */
function decompressData(string $data): string
{
    // gzip magic: 1F 8B
    if (strncmp($data, "\x1f\x8b", 2) === 0) {
        $decoded = @gzdecode($data);
        if ($decoded === false) {
            throw new RuntimeException('Failed to gzdecode data');
        }
        return $decoded;
    }

    // zip magic: PK\x03\x04
    if (strncmp($data, "\x50\x4b\x03\x04", 4) === 0) {
        $tmp = tempnam(sys_get_temp_dir(), 'epgzip');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temp file for ZIP data');
        }
        file_put_contents($tmp, $data);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Failed to open ZIP archive');
        }
        // extract first file from archive into string
        if ($zip->numFiles < 1) {
            $zip->close();
            @unlink($tmp);
            throw new RuntimeException('ZIP archive is empty');
        }
        $first = $zip->getNameIndex(0);
        $stream = $zip->getStream($first);
        if ($stream === false) {
            $zip->close();
            @unlink($tmp);
            throw new RuntimeException('Failed to get stream from ZIP entry');
        }
        $contents = stream_get_contents($stream);
        fclose($stream);
        $zip->close();
        @unlink($tmp);
        if ($contents === false) {
            throw new RuntimeException('Failed to read ZIP entry contents');
        }
        return $contents;
    }

    // assume plain (uncompressed) XML
    return $data;
}

function parseXml(string $xmlString)
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) {
        $errs = libxml_get_errors();
        $msg = [];
        foreach ($errs as $e) {
            $msg[] = trim($e->message);
        }
        libxml_clear_errors();
        throw new RuntimeException('XML parse error: ' . implode('; ', $msg));
    }
    return $xml;
}

function addEpg($url, $xml)
{
    $xmlContent = fetchUrl($url);
    if ($xmlContent === false) {
        die("Failed to fetch XML from $url");
    }
    $xmlChannel = simplexml_load_string($xmlContent);
    if ($xmlChannel === false) {
        die("Failed to parse XML");
    }

    foreach ($xmlChannel->channel as $channel) {
        // Set the id attribute to the contents of <display-name>
        if (isset($channel->{'display-name'})) {
            $channel['id'] = (string)$channel->{'display-name'};
            $newChannelId = $channel['id'];
        }
        // Clone channel into $xml
        if (isset($xml->channel)) {
            $newChannel = $xml->addChild('channel');
            foreach ($channel->children() as $child) {
                $newChild = $newChannel->addChild($child->getName(), (string)$child);
                foreach ($child->attributes() as $attrName => $attrValue) {
                    $newChild->addAttribute($attrName, (string)$attrValue);
                }
            }
            foreach ($channel->attributes() as $attrName => $attrValue) {
                $newChannel->addAttribute($attrName, (string)$attrValue);
            }
        }

        // Clone each <programme> from $xmlChannel into $xml, set channel attribute
        if (isset($xmlChannel->programme)) {
            foreach ($xmlChannel->programme as $programme) {
                $newProgramme = $xml->addChild('programme');
                foreach ($programme->children() as $child) {
                    $newChild = $newProgramme->addChild($child->getName(), (string)$child);
                    foreach ($child->attributes() as $attrName => $attrValue) {
                        $newChild->addAttribute($attrName, (string)$attrValue);
                    }
                }
                foreach ($programme->attributes() as $attrName => $attrValue) {
                    // Set channel attribute to $newChannelId, others as is
                    if ($attrName === 'channel') {
                        $newProgramme->addAttribute('channel', (string)$newChannelId);
                    } else {
                        $newProgramme->addAttribute($attrName, (string)$attrValue);
                    }
                }
            }
        }
    }
}

function fetchUrl(string $url): string
{
    $data = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $data = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $err || $code >= 400) {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: text/plain; charset=utf-8', true, 502);
            }
            echo $err ?: ($data === false ? 'Failed to fetch data' : "HTTP error: {$code}");
            exit(1);
        }
    } else {
        $context = stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 60]]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            if (php_sapi_name() !== 'cli') {
                header('Content-Type: text/plain; charset=utf-8', true, 502);
            }
            echo "Failed to fetch {$url}";
            exit(1);
        }
    }
    return $data;
}

// Fetch the Last-Modified header from a URL
function fetchUrlLastModified(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $data = curl_exec($ch);
        if ($data !== false) {
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                if (stripos($line, 'Last-Modified:') === 0) {
                    curl_close($ch);
                    return trim(substr($line, 14));
                }
            }
        }
        curl_close($ch);
    } else {
        $opts = ["http" => ["method" => "HEAD", "timeout" => 30]];
        $context = stream_context_create($opts);
        $fp = @fopen($url, 'r', false, $context);
        if ($fp) {
            $meta = stream_get_meta_data($fp);
            fclose($fp);
            if (isset($meta['wrapper_data'])) {
                foreach ($meta['wrapper_data'] as $header) {
                    if (stripos($header, 'Last-Modified:') === 0) {
                        return trim(substr($header, 14));
                    }
                }
            }
        }
    }
    return null;
}

$outputFile = __DIR__ . '/cached.output.xml';
$url = 'https://www.open-epg.com/generate/PKUYFvNN9n.xml.gz';

// Check if cached.output.xml exists and is valid and return its contents if so
if (file_exists($outputFile)) {
    $lastModified = fetchUrlLastModified($url);
    if ($lastModified) {
        $remoteTime = strtotime($lastModified);
        $localTime = filemtime($outputFile);
        if ($remoteTime < $localTime) {
            header('Content-Type: application/xml; charset=utf-8');
            readfile($outputFile);
            exit(0);
        }
    }
}

// No cached output or cache is stale. fetch and parse from remote source
/* Fetch into memory (supports cURL or file_get_contents fallback), decompress and parse */
$data = fetchUrl($url);

try {
    $uncompressed = decompressData($data);
} catch (Throwable $e) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8', true, 502);
    }
    echo 'Decompression error: ' . $e->getMessage();
    exit(1);
}

try {
    $xml = parseXml($uncompressed);
} catch (Throwable $e) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8', true, 502);
    }
    echo 'XML parse error: ' . $e->getMessage();
    exit(1);
}

// Merge in additional EPG data from multiple sources

// Array of EPG URLs
$urls = [
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469226", // KOMO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467245", // KIRO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466653", // KCPQ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469833", // KING-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467316", // KCTS-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467943", // WSB-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466443", // WAGA-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466182", // WXIA-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468778", // WMAR-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466108", // WJZ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467728", // WBFF-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466296", // WBAL-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467472", // WCVB-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468717", // WBZ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466875", // WFXT-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=465922", // WBTS-CD
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468288", // WBTV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468354", // WJZY-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469683", // WCNC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469785", // WCPO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467635", // WKRC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466942", // WXIX-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468543", // WLWT-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467280", // WEWS-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467175", // WOIO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466884", // WJW-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466677", // WKYC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=470253", // WXYZ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467812", // WWJ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468599", // WJBK-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=465919", // WDIV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468414", // WBAY-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468432", // WFRV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468769", // WLUK-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467573", // WGBA-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=470077", // KTRK-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467277", // KRIV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468285", // KPRC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469358", // WRTV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469302", // WXIN-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467521", // WTHR-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468737", // WJXX-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469219", // WJAX-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469143", // WFOX-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468727", // WTLV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469802", // KMBC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468753", // KCTV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469704", // WDAF-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467910", // KSHB-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=465978", // KTNV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466192", // KVVU-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=465891", // KSNV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=470185", // KABC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469710", // KCAL-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466724", // KTTV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469871", // KNBC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466286", // WPLG-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468203", // WTVJ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469016", // KSTP-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469181", // WCCO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468858", // KMSP-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468830", // KARE-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466144", // WTVF-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469772", // KTVN-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469834", // WSMV-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467547", // WGNO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466431", // WWL-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468245", // WVUE-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468220", // WDSU-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468623", // KDKA-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466957", // WPGH-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468942", // WPXI-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467172", // WPVI-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467842", // KYW-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467556", // WTXF-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468233", // WCAU-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=466094", // KPHO-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469126", // KSAZ-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467013", // KPNX-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468990", // WFTS-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=469856", // WTVT-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=468189", // WTTG-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=467604", // WRC-DT
    "https://epg.pw/api/epg.xml?lang=en&channel_id=397403", // Astro Grandstand
    "https://epg.pw/api/epg.xml?lang=en&channel_id=397399", // Astro Football
    "https://epg.pw/api/epg.xml?lang=en&channel_id=397396", // Astro Premier League
    "https://epg.pw/api/epg.xml?lang=en&channel_id=399520", // Astro Cricket
];

// Process each URL in the array
foreach ($urls as $url) {
    addEpg($url, $xml);
}


// Save the contents of $xml as a file
file_put_contents($outputFile, $xml->asXML());

header('Content-Type: application/xml; charset=utf-8');
echo $xml->asXML();
exit(0);