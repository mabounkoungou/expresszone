<?php

/**
 * SIMPLIFIED IMPORT FUNCTION
 * 
 * This is a simplified version of the ClientController@import method
 * that handles Excel parsing more robustly without relying on WithHeadingRow.
 * 
 * Usage: Replace the import() method in ClientController with this version.
 */

// Place this in app\Http\Controllers\ClientController.php to replace the existing import() method

public function import(Request $request)
{
    $this->authorizeForUser($request->user('api'), 'customers_import', Client::class);

    // File validation
    $v = Validator::make($request->all(), [
        'customers' => 'required|file|mimes:xls,xlsx|max:20480',
    ]);
    if ($v->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed',
            'errors' => $v->errors()->all(),
        ], 422);
    }

    try {
        // Parse Excel without relying on WithHeadingRow
        // This reads raw array format [0][row_num][col_num]
        $rows = Excel::toArray(new \App\Imports\CustomerImport, $request->file('customers'));
        $sheet = $rows[0] ?? [];

        // Detect if we have data
        if (count($sheet) < 2) {
            return response()->json([
                'status' => false,
                'message' => 'File has insufficient data.',
                'errors' => ['Excel file must have at least a header row and one data row. Found ' . count($sheet) . ' rows.'],
            ], 422);
        }

        // Extract headers from first row
        $headerRow = $sheet[0];
        if (!is_array($headerRow)) {
            throw new \Exception('First row is not an array - invalid file format');
        }

        // Normalize header names
        $headers = [];
        foreach ($headerRow as $idx => $headerValue) {
            $normalized = $this->normalizeKey((string) $headerValue);
            $headers[$idx] = $this->resolveSynonym($normalized);
        }

        // Process data rows
        $normalized = [];
        for ($rowIdx = 1; $rowIdx < count($sheet); $rowIdx++) {
            $dataRow = $sheet[$rowIdx];
            if (!is_array($dataRow) || empty(array_filter($dataRow))) {
                continue; // Skip empty rows
            }

            $rowData = [];
            foreach ($headers as $cellIdx => $columnName) {
                $rowData[$columnName] = $dataRow[$cellIdx] ?? null;
            }

            $normalized[] = $rowData;
        }

        if (empty($normalized)) {
            return response()->json([
                'status' => false,
                'message' => 'No valid data found.',
                'errors' => ['File has headers but no data rows.'],
            ], 422);
        }

        // Validate rows
        $errors = [];
        $prepared = [];
        $codesInFile = [];
        $emailsInFile = [];

        foreach ($normalized as $rowIdx => $row) {
            $lineNum = $rowIdx + 2; // +2 because Excel is 1-indexed and headers are row 1
            
            $name = isset($row['name']) ? trim((string) $row['name']) : '';
            $code = $row['code'] ?? null;
            $email = isset($row['email']) ? trim((string) $row['email']) : '';
            $firstname = isset($row['firstname']) ? trim((string) $row['firstname']) : '';
            $lastname = isset($row['lastname']) ? trim((string) $row['lastname']) : '';
            $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
            $country = isset($row['country']) ? trim((string) $row['country']) : '';
            $city = isset($row['city']) ? trim((string) $row['city']) : '';
            $state = isset($row['state']) ? trim((string) $row['state']) : '';
            $zip = isset($row['zip']) ? trim((string) $row['zip']) : '';
            $adresse = isset($row['adresse']) ? trim((string) $row['adresse']) : '';
            $tax_number = isset($row['tax_number']) ? trim((string) $row['tax_number']) : '';
            $opening_balance_raw = $row['opening_balance'] ?? null;

            // Validate required fields
            if (empty($name)) {
                $errors[] = "Row $lineNum: 'name' is required.";
                continue;
            }

            if ($code === null || $code === '') {
                $errors[] = "Row $lineNum: 'code' is required.";
                continue;
            }

            if (!is_numeric($code) || intval($code) != $code) {
                $errors[] = "Row $lineNum: 'code' must be an integer, got '$code'.";
                continue;
            }

            $code = intval($code);

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row $lineNum: 'email' is not a valid email address.";
                continue;
            }

            // Check file-level duplicates
            if (isset($codesInFile[$code])) {
                $errors[] = "Row $lineNum: Code '$code' is duplicated (also appears in row {$codesInFile[$code]}).";
                continue;
            }
            $codesInFile[$code] = $lineNum;

            if (!empty($email)) {
                if (isset($emailsInFile[$email])) {
                    $errors[] = "Row $lineNum: Email '$email' is duplicated (also appears in row {$emailsInFile[$email]}).";
                    continue;
                }
                $emailsInFile[$email] = $lineNum;
            }

            // Parse opening balance
            $opening_balance = 0.0;
            if ($opening_balance_raw !== null && $opening_balance_raw !== '') {
                if (!is_numeric($opening_balance_raw)) {
                    $errors[] = "Row $lineNum: 'opening_balance' must be numeric, got '$opening_balance_raw'.";
                    continue;
                }
                $opening_balance = (float) $opening_balance_raw;
            }

            // Add to prepared list
            $prepared[] = [
                'name' => $name,
                'firstname' => $firstname ?: null,
                'lastname' => $lastname ?: null,
                'code' => $code,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'country' => $country ?: null,
                'city' => $city ?: null,
                'state' => $state ?: null,
                'zip' => $zip ?: null,
                'adresse' => $adresse ?: null,
                'tax_number' => $tax_number ?: null,
                'opening_balance' => $opening_balance,
            ];
        }

        // Check database duplicates
        if (!empty($errors)) {
            return response()->json([
                'status' => false,
                'message' => 'Validation errors found.',
                'errors' => $errors,
            ], 422);
        }

        if (empty($prepared)) {
            return response()->json([
                'status' => false,
                'message' => 'No valid records to import.',
                'errors' => ['All rows failed validation.'],
            ], 422);
        }

        // Check for DB duplicates
        $codes = array_column($prepared, 'code');
        $existingCodes = Client::whereNull('deleted_at')->whereIn('code', $codes)->pluck('code')->toArray();
        if (!empty($existingCodes)) {
            $dupErrors = array_map(fn($c) => "Code '$c' already exists in the database.", $existingCodes);
            return response()->json([
                'status' => false,
                'message' => 'Database conflicts found.',
                'errors' => $dupErrors,
            ], 422);
        }

        $emails = array_filter(array_column($prepared, 'email'));
        if (!empty($emails)) {
            $existingEmails = Client::whereNull('deleted_at')
                ->whereIn('email', $emails)
                ->pluck('email')
                ->toArray();
            if (!empty($existingEmails)) {
                $dupErrors = array_map(fn($e) => "Email '$e' already exists in the database.", $existingEmails);
                return response()->json([
                    'status' => false,
                    'message' => 'Database conflicts found.',
                    'errors' => $dupErrors,
                ], 422);
            }
        }

        // Insert customers
        $now = now();
        $insertRows = [];
        foreach ($prepared as $r) {
            $insertRows[] = [
                'name' => $r['name'],
                'firstname' => $r['firstname'],
                'lastname' => $r['lastname'],
                'code' => $r['code'],
                'email' => $r['email'],
                'phone' => $r['phone'],
                'country' => $r['country'],
                'city' => $r['city'],
                'state' => $r['state'],
                'zip' => $r['zip'],
                'adresse' => $r['adresse'],
                'tax_number' => $r['tax_number'],
                'opening_balance' => $r['opening_balance'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($insertRows) {
            foreach (array_chunk($insertRows, 1000) as $chunk) {
                Client::insert($chunk);
            }
        });

        return response()->json([
            'status' => true,
            'imported' => count($insertRows),
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'An error occurred during import.',
            'errors' => [$e->getMessage()],
        ], 500);
    }
}
