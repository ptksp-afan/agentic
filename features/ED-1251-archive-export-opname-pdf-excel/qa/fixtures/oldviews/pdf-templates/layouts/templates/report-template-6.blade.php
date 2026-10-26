@extends('v5::pdf-templates.layouts.master')
@inject('pdfTemplate', 'Modules\V5\Http\Services\Setup\Setting\PdfTemplateService')

@php
    $pdfTemplate->setLanguage($template['language'] ?? null);

    $idHeaderPdfTemplate = $template['id_header_pdf_template'] ?? ($template['header']['id_header_pdf_template'] ?? '');
    $idFooterPdfTemplate = $template['id_footer_pdf_template'] ?? ($template['footer']['id_footer_pdf_template'] ?? '');
@endphp

@push('styles')
    @include('v5::pdf-templates.styles.templates.report-template-6')
@endpush

@section('fileName')
    <title>{{ $pdfTemplate->data(['file_name'], $transaction) }}</title>
@endsection

@section('template')
    @includeWhen($idHeaderPdfTemplate === 'report-header-1', 'v5::pdf-templates.layouts.headers.report-header-1')

    <div class="report-template-6 content">
        <div class="table-wrapper">

            {!! $content !!}

        </div>
    </div>

    @includeWhen($idFooterPdfTemplate === 'report-footer-1', 'v5::pdf-templates.layouts.footers.report-footer-1')
@endsection
