<?php
declare(strict_types=1);

/**
 * Test cases for EPG XML processing
 */

require_once __DIR__ . '/epggen.php';

class EpgTests {
    private $xmlWriter;
    private $fileWriter;
    private $tempFile;

    protected function setUp(): void {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'epg_test');
        $this->xmlWriter = new XMLWriter();
        $this->xmlWriter->openMemory();
        $this->fileWriter = new XMLWriter();
        $this->fileWriter->openURI($this->tempFile);
    }

    protected function tearDown(): void {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testOpenEpgProcessing(): void {
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
    <channel id="original.id">
        <display-name>Channel Name</display-name>
        <icon src="http://example.com/icon.png"/>
    </channel>
    <programme start="20251108000000" channel="original.id">
        <title>Test Program</title>
        <desc>Test Description</desc>
    </programme>
</tv>
XML;

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('tv');
        $this->fileWriter->startDocument('1.0', 'UTF-8');
        $this->fileWriter->startElement('tv');

        processEpgSax($sampleXml, $this->xmlWriter, $this->fileWriter, true);

        $this->xmlWriter->endElement(); // tv
        $this->xmlWriter->endDocument();
        $this->fileWriter->endElement(); // tv
        $this->fileWriter->endDocument();

        $output = $this->xmlWriter->outputMemory(true);
        $expected = $sampleXml;

        if (trim($output) !== trim($expected)) {
            echo "TEST FAILED: openEpg XML not preserved exactly\n";
            echo "Expected:\n" . $expected . "\n";
            echo "Got:\n" . $output . "\n";
        } else {
            echo "TEST PASSED: openEpg XML preserved correctly\n";
        }
    }

    public function testEpgPwProcessing(): void {
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
    <channel id="original.id">
        <display-name>New Channel Name</display-name>
        <icon src="http://example.com/icon.png"/>
    </channel>
    <programme start="20251108000000" channel="original.id">
        <title>Test Program</title>
        <desc>Test Description</desc>
    </programme>
</tv>
XML;

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('tv');
        $this->fileWriter->startDocument('1.0', 'UTF-8');
        $this->fileWriter->startElement('tv');

        $channelIdMap = [];
        processEpgSax($sampleXml, $this->xmlWriter, $this->fileWriter, false, $channelIdMap);

        $this->xmlWriter->endElement(); // tv
        $this->xmlWriter->endDocument();
        $this->fileWriter->endElement(); // tv
        $this->fileWriter->endDocument();

        $output = $this->xmlWriter->outputMemory(true);

        // Check if channel id was changed to display-name
        if (strpos($output, 'id="New Channel Name"') === false) {
            echo "TEST FAILED: Channel ID not updated to display-name\n";
        } else {
            echo "TEST PASSED: Channel ID updated correctly\n";
        }

        // Check if programme channel ref was updated
        if (strpos($output, 'channel="New Channel Name"') === false) {
            echo "TEST FAILED: Programme channel reference not updated\n";
        } else {
            echo "TEST PASSED: Programme channel reference updated correctly\n";
        }
    }

    public function testMissingDisplayName(): void {
        $sampleXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
    <channel id="original.id">
        <icon src="http://example.com/icon.png"/>
    </channel>
</tv>
XML;

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('tv');
        $this->fileWriter->startDocument('1.0', 'UTF-8');
        $this->fileWriter->startElement('tv');

        $channelIdMap = [];
        processEpgSax($sampleXml, $this->xmlWriter, $this->fileWriter, false, $channelIdMap);

        $this->xmlWriter->endElement(); // tv
        $this->xmlWriter->endDocument();
        $this->fileWriter->endElement(); // tv
        $this->fileWriter->endDocument();

        $output = $this->xmlWriter->outputMemory(true);

        // Check if original id was preserved when display-name is missing
        if (strpos($output, 'id="original.id"') === false) {
            echo "TEST FAILED: Original ID not preserved when display-name missing\n";
        } else {
            echo "TEST PASSED: Original ID preserved when display-name missing\n";
        }
    }

    public function runAllTests(): void {
        echo "\nRunning EPG XML Processing Tests\n";
        echo "================================\n\n";

        $this->setUp();
        $this->testOpenEpgProcessing();
        $this->tearDown();

        $this->setUp();
        $this->testEpgPwProcessing();
        $this->tearDown();

        $this->setUp();
        $this->testMissingDisplayName();
        $this->tearDown();
    }
}

// Run the tests
$tests = new EpgTests();
$tests->runAllTests();