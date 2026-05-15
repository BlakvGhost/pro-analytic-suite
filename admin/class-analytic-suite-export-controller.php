<?php
/**
 * Export handling.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles report exports.
 */
class Analytic_Suite_Export_Controller {

    /**
     * Dashboard service.
     *
     * @var Analytic_Suite_Dashboard_Service
     */
    private $dashboard_service;

    /**
     * Constructor.
     *
     * @param Analytic_Suite_Dashboard_Service $dashboard_service Dashboard service.
     */
    public function __construct( Analytic_Suite_Dashboard_Service $dashboard_service ) {
        $this->dashboard_service = $dashboard_service;
    }

    /**
     * Streams a CSV export.
     */
    public function export_csv() {
        $this->authorize_export();
        check_admin_referer( 'analytic_suite_export_csv' );

        $filters  = $this->dashboard_service->get_filters_from_request( $_POST );
        $rows     = $this->dashboard_service->get_export_rows( $filters );
        $filename = 'analytics-' . current_time( 'Y-m-d-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );

        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Streams an Excel-compatible export.
     */
    public function export_excel() {
        $this->authorize_export();
        check_admin_referer( 'analytic_suite_export_excel' );

        $filters  = $this->dashboard_service->get_filters_from_request( $_POST );
        $rows     = $this->dashboard_service->get_export_rows( $filters );
        $filename = 'analytics-' . current_time( 'Y-m-d-His' ) . '.xls';

        nocache_headers();
        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="utf-8"><style>';
        echo 'table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px;}';
        echo 'th{background:#10231f;color:#fff;}td,th{border:1px solid #cbd8d2;padding:8px 10px;}';
        echo '</style></head><body>';
        echo '<h1>Pro Analytics</h1>';
        echo '<p>Généré le ' . esc_html( current_time( 'Y-m-d H:i:s' ) ) . '</p>';
        echo '<table><tbody>';

        foreach ( $rows as $index => $row ) {
            echo '<tr>';
            foreach ( $row as $cell ) {
                $tag = 0 === $index ? 'th' : 'td';
                echo '<' . $tag . '>' . esc_html( (string) $cell ) . '</' . $tag . '>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * Streams a PDF summary export.
     */
    public function export_pdf() {
        $this->authorize_export();
        check_admin_referer( 'analytic_suite_export_pdf' );

        $filters  = $this->dashboard_service->get_filters_from_request( $_POST );
        $rows     = $this->dashboard_service->get_export_rows( $filters );
        $filename = 'analytics-' . current_time( 'Y-m-d-His' ) . '.pdf';
        $pdf      = $this->build_pdf( $rows );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );

        echo $pdf;
        exit;
    }

    /**
     * Checks export permission.
     */
    private function authorize_export() {
        if ( ! current_user_can( 'analytic_suite_manage_analytics' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'analytic-suite' ) );
        }
    }

    /**
     * Builds a small native PDF document.
     *
     * @param array $rows Export rows.
     * @return string
     */
    private function build_pdf( $rows ) {
        $lines   = $this->build_pdf_lines( $rows );
        $pages   = array_chunk( $lines, 34 );
        $objects = array();

        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $page_count = count( $pages );
        $kids       = array();

        for ( $i = 0; $i < $page_count; $i++ ) {
            $kids[] = ( 3 + ( $i * 2 ) ) . ' 0 R';
        }

        $objects[] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $page_count . ' >>';

        foreach ( $pages as $page_index => $page_lines ) {
            $page_object_id    = 3 + ( $page_index * 2 );
            $content_object_id = $page_object_id + 1;
            $content           = $this->build_pdf_page_content( $page_lines, $page_index + 1, $page_count );

            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> /F2 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> >> >> /Contents ' . $content_object_id . ' 0 R >>';
            $objects[] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
        }

        return $this->compile_pdf_objects( $objects );
    }

    /**
     * Builds PDF text lines from export rows.
     *
     * @param array $rows Export rows.
     * @return array
     */
    private function build_pdf_lines( $rows ) {
        $lines = array(
            array( 'Pro Analytics', '', true ),
            array( 'Rapport généré le', current_time( 'Y-m-d H:i:s' ), false ),
            array( '', '', false ),
        );

        foreach ( array_slice( $rows, 1 ) as $row ) {
            $lines[] = array(
                isset( $row[0] ) ? (string) $row[0] : '',
                isset( $row[1] ) ? (string) $row[1] : '',
                false,
            );
        }

        return $lines;
    }

    /**
     * Builds a PDF page content stream.
     *
     * @param array $lines      Page lines.
     * @param int   $page       Current page.
     * @param int   $page_count Page count.
     * @return string
     */
    private function build_pdf_page_content( $lines, $page, $page_count ) {
        $content = "q\n";
        $content .= "0.95 0.98 0.97 rg 0 0 595 842 re f\n";
        $content .= "0.06 0.14 0.12 rg 36 770 523 38 re f\n";
        $content .= "BT /F2 16 Tf 1 1 1 rg 52 783 Td (Pro Analytics Suite) Tj ET\n";
        $content .= "0 0 0 rg\n";

        $y = 730;

        foreach ( $lines as $line ) {
            $label = $this->pdf_escape( $line[0] );
            $value = $this->pdf_escape( $line[1] );

            if ( ! empty( $line[2] ) ) {
                $content .= "BT /F2 18 Tf 0.07 0.13 0.11 rg 52 {$y} Td ({$label}) Tj ET\n";
                $y -= 30;
                continue;
            }

            if ( '' === $label && '' === $value ) {
                $y -= 14;
                continue;
            }

            $content .= "0.87 0.91 0.89 RG 52 " . ( $y - 8 ) . " 491 22 re S\n";
            $content .= "BT /F1 10 Tf 0.38 0.45 0.42 rg 62 {$y} Td ({$label}) Tj ET\n";
            $content .= "BT /F2 10 Tf 0.07 0.13 0.11 rg 360 {$y} Td ({$value}) Tj ET\n";
            $y -= 24;
        }

        $footer = $this->pdf_escape( 'Page ' . $page . '/' . $page_count );
        $content .= "BT /F1 9 Tf 0.38 0.45 0.42 rg 500 34 Td ({$footer}) Tj ET\n";
        $content .= "Q";

        return $content;
    }

    /**
     * Compiles PDF objects into a document.
     *
     * @param array $objects Objects without object wrappers.
     * @return string
     */
    private function compile_pdf_objects( $objects ) {
        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array( 0 );

        foreach ( $objects as $index => $object ) {
            $offsets[] = strlen( $pdf );
            $pdf      .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref_offset = strlen( $pdf );
        $pdf        .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n";
        $pdf        .= "0000000000 65535 f \n";

        for ( $i = 1; $i <= count( $objects ); $i++ ) {
            $pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
        }

        $pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;
    }

    /**
     * Escapes text for PDF strings.
     *
     * @param string $text Text.
     * @return string
     */
    private function pdf_escape( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );

        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
            if ( false !== $converted ) {
                $text = $converted;
            }
        } else {
            $text = remove_accents( $text );
        }

        $text = substr( $text, 0, 96 );

        return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
    }
}
