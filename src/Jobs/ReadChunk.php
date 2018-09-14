<?php

namespace Maatwebsite\Excel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Filters\ChunkReadFilter;
use Maatwebsite\Excel\Sheet;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

class ReadChunk implements ShouldQueue
{
    use Queueable;

    /**
     * @var IReader
     */
    private $reader;

    /**
     * @var string
     */
    private $file;

    /**
     * @var string
     */
    private $sheetName;

    /**
     * @var object
     */
    private $sheetImport;

    /**
     * @var int
     */
    private $startRow;

    /**
     * @var int
     */
    private $chunkSize;

    /**
     * @param IReader $reader
     * @param string  $file
     * @param string  $sheetName
     * @param object  $sheetImport
     * @param int     $startRow
     * @param int     $chunkSize
     */
    public function __construct(IReader $reader, string $file, string $sheetName, $sheetImport, int $startRow, int $chunkSize)
    {
        $this->reader      = $reader;
        $this->file        = $file;
        $this->sheetName   = $sheetName;
        $this->sheetImport = $sheetImport;
        $this->startRow    = $startRow;
        $this->chunkSize   = $chunkSize;
    }

    /**
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function handle()
    {
        $filter = new ChunkReadFilter(
            $this->startRow,
            $this->chunkSize,
            $this->sheetName
        );

        $this->reader->setReadFilter($filter);
        $this->reader->setReadDataOnly(true);
        $this->reader->setReadEmptyCells(false);

        $spreadsheet = $this->reader->load($this->file);

        $sheet = new Sheet(
            $spreadsheet->getSheetByName($this->sheetName)
        );

        $sheet->import(
            $this->sheetImport,
            $this->startRow,
            $this->startRow + $this->chunkSize
        );

        $sheet->disconnect();

        unset($sheet, $spreadsheet);
    }
}