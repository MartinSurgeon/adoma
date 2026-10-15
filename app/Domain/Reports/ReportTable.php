<?php

namespace App\Domain\Reports;

/**
 * One table of a report, as the screen shows it and as the spreadsheet download writes it.
 *
 * Every report is built from these, so they all look and behave alike. A cell is the text people read.
 * A row is a list of cells in the order of the columns, or a heading that spans the table.
 */
final class ReportTable
{
    /**
     * @param  list<array{label: string, number?: bool}>  $columns  number: a figure, shown on the right
     * @param  list<list<string>|array{heading: string}>  $rows
     * @param  list<string>|null  $totals  a row of cells under the table
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
    ) {
    }

    public function toArray(): array
    {
        return ['title' => $this->title, 'columns' => $this->columns, 'rows' => $this->rows, 'totals' => $this->totals];
    }

    /** The rows as lines of a spreadsheet file. Figures lose their thousands commas so a spreadsheet reads them as numbers. */
    public function csvRows(): array
    {
        $numeric = array_map(fn (array $column) => (bool) ($column['number'] ?? false), $this->columns);
        $clean = fn (array $cells) => array_map(
            fn ($cell, $i) => ($numeric[$i] ?? false) ? str_replace(',', '', (string) $cell) : (string) $cell,
            $cells,
            array_keys($cells),
        );

        $lines = [array_column($this->columns, 'label')];

        foreach ($this->rows as $row) {
            if (isset($row['heading'])) {
                $lines[] = [$row['heading']];

                continue;
            }

            $lines[] = $clean($row);
        }

        if ($this->totals !== null) {
            $lines[] = $clean($this->totals);
        }

        return $lines;
    }
}
