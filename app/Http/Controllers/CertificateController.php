<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpOffice\PhpWord\TemplateProcessor;

class CertificateController extends Controller
{
    public function generate(Request $request)
    {
        // 1. Tanggapin ang mga data galing sa frontend form
        $rawType = $request->input('certificate_type');
        
        // 2. I-map ang text mula sa form papunta sa totoong file name (lowercase at may underscore)
        $fileNames = [
            'Residency' => 'residency',
            'Indigency' => 'indigency',
            'Delayed PSA Registration' => 'delayed_psa_registration',
            'Marriage Cohabitation' => 'marriage_cohabitation',
            'Scholarship Certificate' => 'scholarship',
            'Tricycle' => 'tricycle',
            'Sari-sari Store' => 'sari_sari_store',
        ];

        // Kunin ang tamang file name. Kung walang match, default sa residency.
        $certType = $fileNames[$rawType] ?? 'residency'; 

        // 3. Hanapin ang template file
        $templatePath = storage_path('app/templates/' . $certType . '.docx');

        if (!file_exists($templatePath)) {
            return response()->json(['error' => 'Template not found at: ' . $templatePath], 404);
        }

        // 4. I-load ang Word template
        $templateProcessor = new TemplateProcessor($templatePath);

        // 5. Palitan ang mga tags ng mga totoong data mula sa form. 
        $templateProcessor->setValue('resident_name', mb_strtoupper($request->input('resident_name')));
        $templateProcessor->setValue('purok', mb_strtoupper($request->input('purok')));
        $templateProcessor->setValue('purpose', mb_strtoupper($request->input('purpose')));
        $templateProcessor->setValue('control_no', $request->input('control_no'));
        $templateProcessor->setValue('current_date', date('jS \d\a\y \o\f F Y'));
        $templateProcessor->setValue('fee', 'P ' . number_format((float)$request->input('fee'), 2));
        
        // Mga signatories
        $templateProcessor->setValue('signatory_name', mb_strtoupper($request->input('signatory_name', '')));
        $templateProcessor->setValue('signatory_title', $request->input('signatory_title', ''));

        // Mga specific tags (halimbawa, para sa Tricycle permit)
        if ($certType === 'tricycle' || $certType === 'sari_sari_store') {
            $templateProcessor->setValue('franchise_no', mb_strtoupper($request->input('franchise_no', 'N/A')));
            $templateProcessor->setValue('business_name', mb_strtoupper($request->input('business_name', '')));
            $templateProcessor->setValue('business_address', mb_strtoupper($request->input('business_address', '')));
        }

        // 6. I-save ang bagong gawang certificate
        $fileName = 'Certificate_' . time() . '.docx';
        $outputPath = storage_path('app/public/' . $fileName);
        
        $templateProcessor->saveAs($outputPath);

        // 7. I-download ng user ang file
        return response()->download($outputPath)->deleteFileAfterSend(true);
    }
}