<?php
namespace EntryVault;

use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal, dependency-free .xlsx writer.
 *
 * An xlsx file is a zip of OOXML parts. Everything we need for a flat export is
 * six small parts, so this writes them directly with ZipArchive rather than
 * pulling in PhpSpreadsheet (megabytes of dependency for one sheet of strings).
 *
 * Every cell is written as an inline string (t="inlineStr"), which sidesteps the
 * shared-string table entirely and keeps numbers, dates and leading zeros
 * exactly as EntryVault stored them. Values are XML-escaped and stripped of the
 * control characters XML 1.0 forbids, so a value containing &, <, > or a stray
 * 0x0B never produces a file Excel refuses to open.
 */
class XLSX_Writer {

    /** Guard against an accidental unbounded export blowing up memory. */
    public const MAX_ROWS = 50000;

    /**
     * Write a single-sheet workbook.
     *
     * @param string               $path       Absolute destination path.
     * @param array<int,string>    $header     Header row cells.
     * @param array<int,array<int,mixed>> $rows Body rows.
     * @param string               $sheet_name Sheet tab name.
     * @return bool True on success.
     */
    public static function write( string $path, array $header, array $rows, string $sheet_name = 'Submissions' ): bool {
        if ( ! class_exists( '\\ZipArchive' ) ) {
            return false;
        }

        $all = [];
        if ( ! empty( $header ) ) {
            $all[] = array_values( $header );
        }
        foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
            $all[] = array_values( (array) $row );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            return false;
        }

        $zip->addFromString( '[Content_Types].xml', self::content_types() );
        $zip->addFromString( '_rels/.rels', self::root_rels() );
        $zip->addFromString( 'xl/workbook.xml', self::workbook( $sheet_name ) );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels() );
        $zip->addFromString( 'xl/styles.xml', self::styles() );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet( $all ) );

        return $zip->close();
    }

    /** Spreadsheet column letter for a zero-based index (0 => A, 26 => AA). */
    public static function column_letter( int $index ): string {
        $letter = '';
        $index++;
        while ( $index > 0 ) {
            $rem    = ( $index - 1 ) % 26;
            $letter = chr( 65 + $rem ) . $letter;
            $index  = intdiv( $index - 1, 26 );
        }
        return $letter;
    }

    /**
     * XML-safe text: drop the control characters XML 1.0 disallows, then escape
     * the five predefined entities.
     *
     * @param mixed $value
     */
    public static function escape( $value ): string {
        $text = (string) $value;
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text );
        if ( null === $text ) {
            // preg_replace returns null on invalid UTF-8; fall back to a byte-safe strip.
            $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value );
        }
        return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8', false );
    }

    /** @param array<int,array<int,mixed>> $rows */
    private static function sheet( array $rows ): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        foreach ( $rows as $r => $cells ) {
            $number = $r + 1;
            $xml   .= '<row r="' . $number . '">';
            foreach ( $cells as $c => $value ) {
                $ref  = self::column_letter( (int) $c ) . $number;
                $text = self::escape( $value );
                if ( '' === $text ) {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"/>';
                    continue;
                }
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private static function content_types(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function root_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook( string $sheet_name ): string {
        // Excel sheet names are capped at 31 chars and cannot contain : \ / ? * [ ]
        $name = preg_replace( '#[:\\\\/?*\[\]]#', ' ', $sheet_name );
        $name = trim( (string) $name );
        $name = '' === $name ? 'Sheet1' : mb_substr( $name, 0, 31 );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape( $name ) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbook_rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }
}
