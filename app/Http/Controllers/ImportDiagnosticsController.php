<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportDiagnosticsController extends BaseController
{
    /**
     * Diagnostic endpoint to debug Excel import issues
     * POST /api/import-diagnostics
     */
    public function diagnose(Request $request)
    {
        if (!$request->hasFile('customers')) {
            return response()->json(['error' => 'No file uploaded'], 400);
        }

        try {
            $file = $request->file('customers');
            
            // Try basic parse
            $rows = Excel::toArray(new \App\Imports\CustomerImport, $file);
            
            $diagnostics = [
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'sheets_count' => count($rows),
                'first_sheet_rows' => count($rows[0] ?? []),
                'first_row_data' => $rows[0][0] ?? null,
                'all_rows_count' => count($rows[0] ?? []),
                'error' => null,
            ];

            if (empty($rows[0])) {
                $diagnostics['error'] = 'First sheet is empty';
            } else {
                // Show structure
                $firstRow = $rows[0][0];
                if (is_array($firstRow)) {
                    $diagnostics['first_row_keys'] = array_keys($firstRow);
                    $diagnostics['first_row_is_associative'] = count(array_filter(array_keys($firstRow), 'is_string')) > 0;
                }
            }

            return response()->json($diagnostics, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
