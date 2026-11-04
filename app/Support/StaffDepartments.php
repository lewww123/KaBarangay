<?php

namespace App\Support;

final class StaffDepartments
{
    public const LABELS = [
        'admin' => 'Barangay Administration',
        'clerk' => 'Records and Certificates',
        'lupon' => 'Lupon Tagapamayapa',
        'senior' => 'Senior Citizens Affairs',
        'pwd' => 'Persons with Disability Affairs',
    ];

    public const POSITIONS = [
        'admin' => 'Barangay Administrator',
        'clerk' => 'Barangay Clerk',
        'lupon' => 'Lupon Officer',
        'senior' => 'Senior Citizens Officer',
        'pwd' => 'PWD Officer',
    ];
}
