<?php

namespace Maatwebsite\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemManager;
use Maatwebsite\Excel\Factories\ReaderFactory;
use Maatwebsite\Excel\Concerns\MapsCsvSettings;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class Reader
{
    use DelegatedMacroable, HasEventBus, MapsCsvSettings;

    /**
     * @var Spreadsheet
     */
    protected $spreadsheet;

    /**
     * @var string
     */
    protected $tmpPath;

    /**
     * @var FilesystemManager
     */
    private $filesystem;

    /**
     * @param FilesystemManager $filesystem
     */
    public function __construct(FilesystemManager $filesystem)
    {
        $this->filesystem = $filesystem;

        $this->tmpPath = config('excel.exports.temp_path', sys_get_temp_dir());
        $this->applyCsvSettings(config('excel.exports.csv', []));

        $this->setDefaultValueBinder();
    }

    /**
     * @param object      $import
     * @param string      $filePath
     * @param string|null $disk
     * @param string|null $readerType
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch|null
     */
    public function read($import, string $filePath, string $disk = null, string $readerType = null)
    {
        if ($import instanceof ShouldQueue && !$import instanceof WithChunkReading) {
            throw new InvalidArgumentException('ShouldQueue is only supported in combination with WithChunkReading.');
        }

        if ($import instanceof WithCustomValueBinder) {
            Cell::setValueBinder($import);
        }

        if ($import instanceof WithCustomCsvSettings) {
            $this->applyCsvSettings($import->getCsvSettings());
        }

        $file = $this->copyToFileSystem($filePath, $disk);

        $reader = ReaderFactory::make($file, $readerType);

        if ($reader instanceof Csv) {
            $reader->setDelimiter($this->delimiter);
            $reader->setEnclosure($this->enclosure);
            $reader->setEscapeCharacter($this->escapeCharacter);
            $reader->setContiguous($this->contiguous);
            $reader->setInputEncoding($this->inputEncoding);
        }

        if ($import instanceof WithChunkReading) {
            return (new ChunkReader)->read($import, $reader, $file);
        }

        $sheetExports = [];
        if ($import instanceof WithMultipleSheets) {
            $sheetExports = $import->sheets();

            if (method_exists($reader, 'setLoadSheetsOnly')) {
                $reader->setLoadSheetsOnly(array_keys($sheetExports));
            }
        }

        $this->spreadsheet = $reader->load($file);

        if (!$import instanceof WithMultipleSheets) {
            $sheetExports = array_fill(0, $this->spreadsheet->getSheetCount(), $import);
        }

        foreach ($sheetExports as $index => $sheetExport) {
            $sheet = $this->loadSheet($index);
            $sheet->import($sheetExport);
            $sheet->disconnect();
        }

        unset($sheetExports, $this->spreadsheet);

        $this->setDefaultValueBinder();
        unlink($file);
    }

    /**
     * @return object
     */
    public function getDelegate()
    {
        return $this->spreadsheet;
    }

    /**
     * @return $this
     */
    public function setDefaultValueBinder()
    {
        Cell::setValueBinder(new DefaultValueBinder);

        return $this;
    }

    /**
     * @param string      $filePath
     * @param string|null $disk
     *
     * @return string
     */
    protected function copyToFileSystem(string $filePath, string $disk = null)
    {
        $tempFilePath = $this->getTmpFile($filePath);
        $tmpStream    = fopen($tempFilePath, 'w+');

        $file = $this->filesystem->disk($disk)->readStream($filePath);

        stream_copy_to_stream($file, $tmpStream);
        fclose($tmpStream);

        return $tempFilePath;
    }

    /**
     * @param string $filePath
     *
     * @return string
     */
    protected function getTmpFile(string $filePath): string
    {
        $tmp = $this->tmpPath . DIRECTORY_SEPARATOR . str_random(16) . '.' . pathinfo($filePath)['extension'];

        return $tmp;
    }

    /**
     * @param string|int $index
     *
     * @return Sheet
     */
    private function loadSheet($index): Sheet
    {
        if (is_numeric($index)) {
            $sheet = $this->spreadsheet->getSheet($index);
        } else {
            $sheet = $this->spreadsheet->getSheetByName($index);
        }

        return new Sheet($sheet);
    }
}
