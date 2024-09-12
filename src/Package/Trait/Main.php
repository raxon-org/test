<?php
namespace Package\Raxon\Test\Trait;

use Raxon\Config;

use Raxon\Exception\DirectoryCreateException;
use Raxon\Module\Cli;
use Raxon\Module\Core;
use Raxon\Module\Event;
use Raxon\Module\Dir;
use Raxon\Module\File;

use Exception;

use Raxon\Exception\FileWriteException;
use Raxon\Exception\FileAppendException;
use Raxon\Exception\ObjectException;


trait Main {

    /**
     * @throws ObjectException
     * @throws FileAppendException
     * @throws Exception
     */
    public function run_test($flags, $options): void
    {
        Core::interactive();
        $object = $this->object();
        if($object->config(Config::POSIX_ID) !== 0){
            $exception = new Exception('Only root can run tests...');
            Event::trigger($object, 'raxon.org.test.main.run.test', [
                'options' => $options,
                'exception' => $exception
            ]);
            throw $exception;
        }
        $dir_ramdisk_test = $object->config('ramdisk.url') .
            $object->config(Config::POSIX_ID) .
            $object->config('ds') .
            'Test' .
            $object->config('ds')
        ;
        Dir::create($dir_ramdisk_test, Dir::CHMOD);
        $url_ramdisk_test = $dir_ramdisk_test . 'composer.json';
        Core::execute($object, 'composer show >> ' . $url_ramdisk_test, $output, $notification);
        $packages = [];
        $output = File::read($url_ramdisk_test);
        if($output){
            $data = explode(PHP_EOL, $output);
            foreach($data as $nr => $line){
                $line = trim($line);
                if($line){
                    $line = explode(' ', $line, 2);
                    $package = $line[0];
                    $record = trim($line[1]);
                    $line = explode(' ', $record, 2);
                    $version = $line[0];
                    $description = trim($line[1]);
                    $packages[$package] = [
                        'name' => $package,
                        'version' => $version,
                        'description' => $description
                    ];
                }
            }
//            echo $output;
        }
        /*
        if($notification){
            echo $notification;
        }
        */
        $output = '';
        $dir = new Dir();
        $dir_vendor = $dir->read($object->config('project.dir.vendor'));
        if(!$dir_vendor){
            $exception = new Exception('No vendor directory found...');
            Event::trigger($object, 'raxon.org.test.main.run.test', [
                'options' => $options,
                'exception' => $exception
            ]);
            throw $exception;
        }
        if(!Dir::is($object->config('project.dir.tests'))){
            Dir::create($object->config('project.dir.tests'), Dir::CHMOD);
        }
        $testsuite = [];

        $testsuite = $this->vendor_copy($flags, $options, $packages, $testsuite);
        $testsuite = $this->domain_copy($flags, $options, $testsuite);
        $this->create_phpunit($flags, $options, $testsuite);
        $this->create_bootstrap($flags, $options, $testsuite);
        $this->pest_init($flags, $options);
        $this->pest_run($flags, $options);
//        echo Cli::labels();
    }

    /**
     * @throws ObjectException
     */
    public function pest_run($flags, $options): void
    {
        $object = $this->object();
        $command = './vendor/bin/pest';
        $code = Core::execute($object, $command, $output, $notification);
        if($output){
            echo $output;
        }
        if($notification){
            echo $notification;
        }
        if($code !== 0){
            $exception = new Exception('Pest Tests failed...');
            Event::trigger($object, 'raxon.org.test.main.run.test', [
                'options' => $options,
                'exception' => $exception,
                'output' => $output,
                'notification' => $notification
            ]);
            exit($code);
        }
    }


    /**
     * @throws ObjectException
     */
    public function pest_init($flags, $options): void
    {
        $object = $this->object();
        $command = './vendor/bin/pest --init';
        $code = Core::execute($object, $command, $output, $notification);
        if($output){
            echo $output;
        }
        if($notification){
            echo $notification;
        }
        if($code !== 0){
            $exception = new Exception('Pest initialization failed...');
            Event::trigger($object, 'raxon.org.test.main.run.test', [
                'options' => $options,
                'exception' => $exception,
                'output' => $output,
                'notification' => $notification
            ]);
            exit($code);
        }
    }

    /**
     * @throws FileWriteException
     */
    public function create_bootstrap($flags, $options): bool | int
    {
        $object = $this->object();
        $write = [];
        $write[] = '<?php';
        $write[] = '';
        $write[] = 'require_once __DIR__ . \'/../vendor/autoload.php\';';
        $write[] = '';
        return File::write($object->config('project.dir.tests') . 'bootstrap.php', implode(PHP_EOL, $write));
    }

    /**
     * @throws FileWriteException
     */
    public function create_phpunit($flags, $options, $testsuite): bool | int
    {
        $object = $this->object();
        $url_xml = $object->config('project.dir.root') . 'phpunit.xml';
        $write = [];
        $write[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $write[] = '<phpunit bootstrap="tests/bootstrap.php"';
        $write[] = '         colors="true">';
        $write[] = '    <testsuites>';
        foreach($testsuite as $nr => $record){
            $write[] = '        <testsuite name="' . $record['name'] . '">';
            $write[] = '            <directory>' . $record['directory'] . '</directory>';
            $write[] = '        </testsuite>';
        }
        $write[] = '    </testsuites>';
        $write[] = '</phpunit>';
        return File::write($url_xml, implode(PHP_EOL, $write));
    }

    public function domain_copy($flags, $options, $testsuite): array
    {
        $object = $this->object();
        $dir = new Dir();
        $domains = $dir->read($object->config('project.dir.domain'));
        $dir_tests = null;
        if(property_exists($options, 'directory_tests')){
            if(is_string($options->directory_tests)){
                $dir_tests = [$options->directory_tests];
            }
            elseif(is_array($options->directory_tests)){
                $dir_tests = $options->directory_tests;
            }
        }
        if($dir_tests === null){
            $dir_tests = [
                'test',
                'tests',
                'Test',
                'Tests'
            ];
        }
        foreach($domains as $nr => $record){
            $dir_domain = $record->url;
            $dir_inner = $dir->read($dir_domain);
            ddd($dir_inner);
            if($dir_inner){
                foreach($dir_inner as $dir_inner_nr => $dir_record){
                    foreach($dir_tests as $dir_test){
                        $dir_test_url = $dir_record->url . $dir_test . $object->config('ds');
                        if(
                            File::exist($dir_test_url) &&
                            Dir::is($dir_test_url)
                        ){
                            $dir_target = $object->config('project.dir.tests') .
                                'Feature' .
                                $object->config('ds') .
                                ucfirst($record->name) .
                                $object->config('ds')
                            ;
                            $testsuite[] = [
                                'name' => $record->name,
                                'directory' => $dir_target
                            ];
                            $dir_test_read = $dir->read($dir_test_url);
                            if($dir_test_read){
                                foreach($dir_test_read as $dir_test_nr => $file){
                                    if($file->type === File::TYPE){
                                        $read = File::read($file->url);
                                        if(str_contains($read, 'PHPUnit\Framework\TestCase')){
                                            //we want pest tests
                                            continue;
                                        }
                                        $target =
                                            $dir_target .
                                            $file->name
                                        ;
                                        if(!Dir::is($dir_target)){
                                            Dir::create($dir_target, Dir::CHMOD);
                                        }
                                        if(!File::exist($target)){
                                            File::copy($file->url, $target);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $testsuite;
    }

    /**
     * @throws DirectoryCreateException
     * @throws ObjectException
     * @throws Exception
     */
    public function vendor_copy($flags, $options, $packages, $testsuite): array
    {
        $object = $this->object();
        $dir = new Dir();
        $dir_vendor = $dir->read($object->config('project.dir.vendor'));
        if(!$dir_vendor){
            $exception = new Exception('No vendor directory found...');
            Event::trigger($object, 'raxon.org.test.main.run.test', [
                'options' => $options,
                'exception' => $exception
            ]);
            throw $exception;
        }
        $dir_tests = null;
        if(property_exists($options, 'directory_tests')){
            if(is_string($options->directory_tests)){
                $dir_tests = [$options->directory_tests];
            }
            elseif(is_array($options->directory_tests)){
                $dir_tests = $options->directory_tests;
            }
        }
        if($dir_tests === null){
            $dir_tests = [
                'test',
                'tests',
                'Test',
                'Tests'
            ];
        }
        //only pest tests are supported
        $testable = [];
        $testable[] = 'raxon';
        if(
            property_exists($options, 'testable') &&
            is_array($options->testable)
        ){
            $testable = $options->testable;
        }
        foreach($dir_vendor as $nr => $record){
            $package = $record->name;
            if(
                in_array(
                    $package,
                    $testable,
                    true
                ) &&
                $record->type === Dir::TYPE
            ){
                $dir_inner = $dir->read($record->url);
                if($dir_inner){
                    foreach($dir_inner as $dir_inner_nr => $dir_record){
                        foreach($dir_tests as $dir_test){
                            $dir_test_url = $dir_record->url . $dir_test . $object->config('ds');
                            if(
                                File::exist($dir_test_url) &&
                                Dir::is($dir_test_url)
                            ){
                                $dir_target = $object->config('project.dir.tests') .
                                    'Feature' .
                                    $object->config('ds') .
                                    ucfirst($dir_record->name) .
                                    $object->config('ds')
                                ;
                                /*
                                $dir_target = $object->config('project.dir.tests');
                                */
                                $testsuite[] = [
                                    'name' => $dir_record->name,
                                    'directory' => $dir_target
                                ];
                                $dir_test_read = $dir->read($dir_test_url);
                                if($dir_test_read){
                                    if(array_key_exists($record->name . '/' . $dir_record->name, $packages)){
                                        $package = $packages[$record->name . '/' . $dir_record->name];
                                        echo Cli::info('Copying', [
                                                'capitals' => true
                                            ]) .
                                            ' tests from ' . $package['name'] .
                                            ' with version: '.
                                            $package['version'] . PHP_EOL
                                        ;
                                        flush();
                                    }
                                    foreach($dir_test_read as $dir_test_nr => $file){
                                        if($file->type === File::TYPE){
                                            $read = File::read($file->url);
                                            if(str_contains($read, 'PHPUnit\Framework\TestCase')){
                                                //we want pest tests
                                                continue;
                                            }
                                            $target =
                                                $dir_target .
                                                $file->name
                                            ;
                                            if(!Dir::is($dir_target)){
                                                Dir::create($dir_target, Dir::CHMOD);
                                            }
                                            if(!File::exist($target)){
                                                File::copy($file->url, $target);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $testsuite;
    }
}
