<?php

/* *****************************************
File: TableExporter.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 共用的表格匯出工具，把「表頭＋資料列」輸出成 Excel(.xlsx) 或 PDF 下載回應；各模塊的列表匯出共用，不要各自重刻。
***************************************** */

namespace App\Support\Export;

use Illuminate\Http\Response;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;

class TableExporter
{
    public const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * 輸出 Excel。所有儲存格一律以字串寫入，避免手機號碼 0912… 掉前導零，
     * 也避免使用者輸入的 `=cmd|...` 被 Excel 當成公式執行（CSV／公式注入）。
     *
     * @param  array<int, string>  $headers  表頭
     * @param  array<int, array<int, mixed>>  $rows  資料列，欄位順序與表頭一致
     */
    public function xlsx(string $title, array $headers, array $rows, string $filename): Response
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        // Excel 工作表名稱上限 31 字元，且不可含 : \ / ? * [ ]
        $sheet->setTitle(mb_substr(preg_replace('/[:\\\\\/?*\[\]]/u', ' ', $title), 0, 31));

        foreach (array_merge([$headers], $rows) as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) ($value ?? ''), DataType::TYPE_STRING);
            }
        }

        $sheet->getStyle([1, 1, max(count($headers), 1), 1])->getFont()->setBold(true);
        foreach (range(1, max(count($headers), 1)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        ob_start();
        (new Xlsx($spreadsheet))->save('php://output');
        $binary = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return $this->download($binary, self::XLSX_MIME, $filename);
    }

    /**
     * 輸出 PDF（A4 橫式）。中文字型使用 mPDF 內建的 Sun-ExtA，不依賴伺服器系統字型。
     *
     * @param  array<int, string>  $headers  表頭
     * @param  array<int, array<int, mixed>>  $rows  資料列，欄位順序與表頭一致
     */
    public function pdf(string $title, array $headers, array $rows, string $filename): Response
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'default_font' => 'sun-exta',
            'default_font_size' => 9,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($this->html($title, $headers, $rows));

        return $this->download($mpdf->Output($filename, 'S'), 'application/pdf', $filename);
    }

    private function html(string $title, array $headers, array $rows): string
    {
        $e = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<style>
            h2 { text-align: center; font-size: 14pt; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 0.3pt solid #999999; padding: 3pt; text-align: left; }
            th { background-color: #e8eefc; }
        </style>';
        $html .= '<h2>'.$e($title).'</h2><table><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.$e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $cell) {
                $html .= '<td>'.$e($cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function download(string $binary, string $mime, string $filename): Response
    {
        return response($binary, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, 'export'.strrchr($filename, '.')),
            'Cache-Control' => 'no-store',
        ]);
    }
}
