<?php
/**
 * includes/validators.php
 * Improvement 14: Data Validation - National ID and vehicle plate format
 * checks, shared by cases/create.php and fines/issue.php.
 *
 * Zimbabwe National ID: 2-digit district code, 6-8 digit sequence number,
 * 1 check letter, 2-digit district code again - e.g. 63-123456A78.
 * Commonly typed with spaces or no separator too, so this normalizes
 * before validating rather than rejecting on punctuation alone.
 *
 * Zimbabwe vehicle plate: current (2006-) format is 3 letters + 4 digits
 * (e.g. ABC 1234). Older 1-2 letter plates are still legally on the road
 * (the reform didn't force replacement of existing plates), so the
 * pattern accepts 2-3 letters to avoid rejecting genuine older vehicles.
 */

const NATIONAL_ID_PATTERN = '/^\d{2}-\d{6,8}[A-Z]\d{2}$/';
const VEHICLE_PLATE_PATTERN = '/^[A-Z]{2,3}\d{3,4}$/';

/** Strips spaces around the hyphen and uppercases, without inventing a hyphen that isn't there. */
function normalizeNationalId(string $id): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($id)));
}

function isValidNationalId(string $id): bool
{
    return $id === '' || preg_match(NATIONAL_ID_PATTERN, normalizeNationalId($id)) === 1;
}

function normalizeVehiclePlate(string $plate): string
{
    $compact = strtoupper(preg_replace('/[\s-]+/', '', trim($plate)));
    if (preg_match(VEHICLE_PLATE_PATTERN, $compact) === 1) {
        // Re-insert a single space between the letters and digits for
        // consistent display (e.g. "abc1234" -> "ABC 1234").
        return preg_replace('/^([A-Z]{2,3})(\d{3,4})$/', '$1 $2', $compact);
    }
    return $compact;
}

function isValidVehiclePlate(string $plate): bool
{
    if ($plate === '') {
        return true;
    }
    $compact = strtoupper(preg_replace('/[\s-]+/', '', trim($plate)));
    return preg_match(VEHICLE_PLATE_PATTERN, $compact) === 1;
}
