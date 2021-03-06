<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for /lib/filestorage/zip_packer.php and zip_archive.php
 *
 * @package   core_files
 * @category  phpunit
 * @copyright 2012 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


class core_files_zip_packer_testcase extends advanced_testcase {
    protected $testfile;
    protected $files;

    protected function setUp() {
        parent::setUp();

        $this->testfile = __DIR__.'/fixtures/test.txt';

        $fs = get_file_storage();
        $context = context_system::instance();
        if (!$file = $fs->get_file($context->id, 'phpunit', 'data', 0, '/', 'test.txt')) {
            $file = $fs->create_file_from_pathname(
                array('contextid'=>$context->id, 'component'=>'phpunit', 'filearea'=>'data', 'itemid'=>0, 'filepath'=>'/', 'filename'=>'test.txt'),
                $this->testfile);
        }

        $this->files = array(
            'test.test' => $this->testfile,
            'testíček.txt' => $this->testfile,
            'Prüfung.txt' => $this->testfile,
            '测试.txt' => $this->testfile,
            '試験.txt' => $this->testfile,
            'Žluťoučký/Koníček.txt' => $file,
        );
    }

    public function test_get_packer() {
        $this->resetAfterTest(false);
        $packer = get_file_packer();
        $this->assertInstanceOf('zip_packer', $packer);

        $packer = get_file_packer('application/zip');
        $this->assertInstanceOf('zip_packer', $packer);
    }

    /**
     * @depends test_get_packer
     */
    public function test_list_files() {
        $this->resetAfterTest(false);

        $files = array(
            __DIR__.'/fixtures/test_moodle_22.zip',
            __DIR__.'/fixtures/test_moodle.zip',
            __DIR__.'/fixtures/test_tc_8.zip',
            __DIR__.'/fixtures/test_7zip_927.zip',
            __DIR__.'/fixtures/test_winzip_165.zip',
            __DIR__.'/fixtures/test_winrar_421.zip',
            __DIR__.'/fixtures/test_thumbsdb.zip',
        );

        if (function_exists('normalizer_normalize')) {
            // Unfortunately there is no way to standardise UTF-8 strings without INTL extension.
            $files[] = __DIR__.'/fixtures/test_infozip_3.zip';
            $files[] = __DIR__.'/fixtures/test_osx_1074.zip';
            $files[] = __DIR__.'/fixtures/test_osx_compress.zip';
        }

        $packer = get_file_packer('application/zip');

        foreach ($files as $archive) {
            $archivefiles = $packer->list_files($archive);
            $this->assertTrue(is_array($archivefiles), "Archive not extracted properly: ".basename($archive).' ');
            $this->assertTrue(count($this->files) === count($archivefiles) or count($this->files) === count($archivefiles) - 1); // Some zippers create empty dirs.
            foreach ($archivefiles as $file) {
                if ($file->pathname === 'Žluťoučký/') {
                    // Some zippers create empty dirs.
                    continue;
                }
                $this->assertArrayHasKey($file->pathname, $this->files, "File $file->pathname not extracted properly: ".basename($archive).' ');
            }
        }

        // Windows packer supports only DOS encoding.
        $archive = __DIR__.'/fixtures/test_win8_de.zip';
        $archivefiles = $packer->list_files($archive);
        $this->assertTrue(is_array($archivefiles), "Archive not extracted properly: ".basename($archive).' ');
        $this->assertEquals(2, count($archivefiles));
        foreach ($archivefiles as $file) {
            $this->assertTrue($file->pathname === 'Prüfung.txt' or $file->pathname === 'test.test');
        }

        $zip_archive = new zip_archive();
        $zip_archive->open(__DIR__.'/fixtures/test_win8_cz.zip', file_archive::OPEN, 'cp852');
        $archivefiles = $zip_archive->list_files();
        $this->assertTrue(is_array($archivefiles), "Archive not extracted properly: ".basename($archive).' ');
        $this->assertEquals(3, count($archivefiles));
        foreach ($archivefiles as $file) {
            $this->assertTrue($file->pathname === 'Žluťoučký/Koníček.txt' or $file->pathname === 'testíček.txt' or $file->pathname === 'test.test');
        }
        $zip_archive->close();

        // Empty archive extraction.
        $archive = __DIR__.'/fixtures/empty.zip';
        $archivefiles = $packer->list_files($archive);
        $this->assertSame(array(), $archivefiles);
    }

    /**
     * @depends test_list_files
     */
    public function test_archive_to_pathname() {
        global $CFG;

        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $archive = "$CFG->tempdir/archive.zip";

        $this->assertFileNotExists($archive);
        $result = $packer->archive_to_pathname($this->files, $archive);
        $this->assertTrue($result);
        $this->assertFileExists($archive);

        $archivefiles = $packer->list_files($archive);
        $this->assertTrue(is_array($archivefiles));
        $this->assertEquals(count($this->files), count($archivefiles));
        foreach ($archivefiles as $file) {
            $this->assertArrayHasKey($file->pathname, $this->files);
        }

        // Test invalid files parameter.
        $archive = "$CFG->tempdir/archive2.zip";
        $this->assertFileNotExists($archive);

        $this->assertFileNotExists(__DIR__.'/xx/yy/ee.txt');
        $files = array('xtest.txt'=>__DIR__.'/xx/yy/ee.txt');

        $result = $packer->archive_to_pathname($files, $archive, false);
        $this->assertFalse($result);
        $this->assertDebuggingCalled();
        $this->assertFileNotExists($archive);

        $result = $packer->archive_to_pathname($files, $archive);
        $this->assertTrue($result);
        $this->assertFileExists($archive);
        $this->assertDebuggingCalled();
        $archivefiles = $packer->list_files($archive);
        $this->assertSame(array(), $archivefiles);
        unlink($archive);

        $this->assertFileNotExists(__DIR__.'/xx/yy/ee.txt');
        $this->assertFileExists(__DIR__.'/fixtures/test.txt');
        $files = array('xtest.txt'=>__DIR__.'/xx/yy/ee.txt', 'test.txt'=>__DIR__.'/fixtures/test.txt', 'ytest.txt'=>__DIR__.'/xx/yy/yy.txt');
        $result = $packer->archive_to_pathname($files, $archive);
        $this->assertTrue($result);
        $this->assertFileExists($archive);
        $archivefiles = $packer->list_files($archive);
        $this->assertCount(1, $archivefiles);
        $this->assertEquals('test.txt', $archivefiles[0]->pathname);
        $dms = $this->getDebuggingMessages();
        $this->assertCount(2, $dms);
        $this->resetDebugging();
        unlink($archive);
    }

    /**
     * @depends test_archive_to_pathname
     */
    public function test_archive_to_storage() {
        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $fs = get_file_storage();
        $context = context_system::instance();

        $this->assertFalse($fs->file_exists($context->id, 'phpunit', 'test', 0, '/', 'archive.zip'));
        $result = $packer->archive_to_storage($this->files, $context->id, 'phpunit', 'test', 0, '/', 'archive.zip');
        $this->assertInstanceOf('stored_file', $result);
        $this->assertTrue($fs->file_exists($context->id, 'phpunit', 'test', 0, '/', 'archive.zip'));

        $archivefiles = $result->list_files($packer);
        $this->assertTrue(is_array($archivefiles));
        $this->assertEquals(count($this->files), count($archivefiles));
        foreach ($archivefiles as $file) {
            $this->assertArrayHasKey($file->pathname, $this->files);
        }
    }

    /**
     * @depends test_archive_to_storage
     */
    public function test_extract_to_pathname() {
        global $CFG;

        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $fs = get_file_storage();
        $context = context_system::instance();

        $target = "$CFG->tempdir/test/";
        $testcontent = file_get_contents($this->testfile);

        @mkdir($target, $CFG->directorypermissions);
        $this->assertTrue(is_dir($target));

        $archive = "$CFG->tempdir/archive.zip";
        $this->assertFileExists($archive);
        $result = $packer->extract_to_pathname($archive, $target);
        $this->assertTrue(is_array($result));
        $this->assertEquals(count($this->files), count($result));
        foreach ($this->files as $file => $unused) {
            $this->assertTrue($result[$file]);
            $this->assertFileExists($target.$file);
            $this->assertSame($testcontent, file_get_contents($target.$file));
        }

        $archive = $fs->get_file($context->id, 'phpunit', 'test', 0, '/', 'archive.zip');
        $this->assertNotEmpty($archive);
        $result = $packer->extract_to_pathname($archive, $target);
        $this->assertTrue(is_array($result));
        $this->assertEquals(count($this->files), count($result));
        foreach ($this->files as $file => $unused) {
            $this->assertTrue($result[$file]);
            $this->assertFileExists($target.$file);
            $this->assertSame($testcontent, file_get_contents($target.$file));
        }
    }

    /**
     * @depends test_archive_to_storage
     */
    public function test_extract_to_pathname_onlyfiles() {
        global $CFG;

        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $fs = get_file_storage();
        $context = context_system::instance();

        $target = "$CFG->tempdir/onlyfiles/";
        $testcontent = file_get_contents($this->testfile);

        @mkdir($target, $CFG->directorypermissions);
        $this->assertTrue(is_dir($target));

        $onlyfiles = array('test', 'test.test', 'Žluťoučký/Koníček.txt', 'Idontexist');
        $willbeextracted = array_intersect(array_keys($this->files), $onlyfiles);
        $donotextract = array_diff(array_keys($this->files), $onlyfiles);

        $archive = "$CFG->tempdir/archive.zip";
        $this->assertFileExists($archive);
        $result = $packer->extract_to_pathname($archive, $target, $onlyfiles);
        $this->assertTrue(is_array($result));
        $this->assertEquals(count($willbeextracted), count($result));

        foreach ($willbeextracted as $file) {
            $this->assertTrue($result[$file]);
            $this->assertFileExists($target.$file);
            $this->assertSame($testcontent, file_get_contents($target.$file));
        }
        foreach ($donotextract as $file) {
            $this->assertFalse(isset($result[$file]));
            $this->assertFileNotExists($target.$file);
        }

    }

    /**
     * @depends test_archive_to_storage
     */
    public function test_extract_to_storage() {
        global $CFG;

        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $fs = get_file_storage();
        $context = context_system::instance();

        $testcontent = file_get_contents($this->testfile);

        $archive = $fs->get_file($context->id, 'phpunit', 'test', 0, '/', 'archive.zip');
        $this->assertNotEmpty($archive);
        $result = $packer->extract_to_storage($archive, $context->id, 'phpunit', 'target', 0, '/');
        $this->assertTrue(is_array($result));
        $this->assertEquals(count($this->files), count($result));
        foreach ($this->files as $file => $unused) {
            $this->assertTrue($result[$file]);
            $stored_file = $fs->get_file_by_hash(sha1("/$context->id/phpunit/target/0/$file"));
            $this->assertInstanceOf('stored_file', $stored_file);
            $this->assertSame($testcontent, $stored_file->get_content());
        }

        $archive = "$CFG->tempdir/archive.zip";
        $this->assertFileExists($archive);
        $result = $packer->extract_to_storage($archive, $context->id, 'phpunit', 'target', 0, '/');
        $this->assertTrue(is_array($result));
        $this->assertEquals(count($this->files), count($result));
        foreach ($this->files as $file => $unused) {
            $this->assertTrue($result[$file]);
            $stored_file = $fs->get_file_by_hash(sha1("/$context->id/phpunit/target/0/$file"));
            $this->assertInstanceOf('stored_file', $stored_file);
            $this->assertSame($testcontent, $stored_file->get_content());
        }
        unlink($archive);
    }

    /**
     * @depends test_extract_to_storage
     */
    public function test_add_files() {
        global $CFG;

        $this->resetAfterTest(false);

        $packer = get_file_packer('application/zip');
        $archive = "$CFG->tempdir/archive.zip";

        $this->assertFileNotExists($archive);
        $packer->archive_to_pathname(array(), $archive);
        $this->assertFileExists($archive);

        $zip_archive = new zip_archive();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(0, $zip_archive->count());

        $zip_archive->add_file_from_string('test.txt', 'test');
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(1, $zip_archive->count());

        $zip_archive->add_directory('test2');
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $files = $zip_archive->list_files();
        $this->assertCount(2, $files);
        $this->assertEquals('test.txt', $files[0]->pathname);
        $this->assertEquals('test2/', $files[1]->pathname);

        $result = $zip_archive->add_file_from_pathname('test.txt', __DIR__.'/nonexistent/file.txt');
        $this->assertFalse($result);
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(2, $zip_archive->count());
        $zip_archive->close();

        unlink($archive);
    }

    /**
     * @depends test_add_files
     */
    public function test_open_archive() {
        global $CFG;

        $this->resetAfterTest(true);

        $archive = "$CFG->tempdir/archive.zip";

        $this->assertFileNotExists($archive);

        $zip_archive = new zip_archive();
        $result = $zip_archive->open($archive, file_archive::OPEN);
        $this->assertFalse($result);
        $this->assertDebuggingCalled();

        $zip_archive = new zip_archive();
        $result = $zip_archive->open($archive, file_archive::CREATE);
        $this->assertTrue($result);
        $zip_archive->add_file_from_string('test.txt', 'test');
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(1, $zip_archive->count());

        $zip_archive = new zip_archive();
        $result = $zip_archive->open($archive, file_archive::OVERWRITE);
        $this->assertTrue($result);
        $zip_archive->add_file_from_string('test2.txt', 'test');
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(1, $zip_archive->count());
        $zip_archive->close();

        unlink($archive);
        $zip_archive = new zip_archive();
        $result = $zip_archive->open($archive, file_archive::OVERWRITE);
        $this->assertTrue($result);
        $zip_archive->add_file_from_string('test2.txt', 'test');
        $zip_archive->close();
        $zip_archive->open($archive, file_archive::OPEN);
        $this->assertEquals(1, $zip_archive->count());
        $zip_archive->close();

        unlink($archive);
    }
}
