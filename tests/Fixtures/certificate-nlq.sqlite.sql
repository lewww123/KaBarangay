INSERT INTO barangays (id, name, code, city_municipality, province, is_active) VALUES
    (9101, 'NLQ Test Barangay', 'NLQ-LOCAL', 'Test City', 'Test Province', 1),
    (9102, 'Foreign NLQ Barangay', 'NLQ-FOREIGN', 'Test City', 'Test Province', 1);

INSERT INTO puroks (id, barangay_id, name, code, is_active) VALUES
    (9101, 9101, 'Purok 1', 'NLQ-P1', 1),
    (9102, 9102, 'Purok 1', 'NLQ-F1', 1);

INSERT INTO resident_registrations (id, public_id, barangay_id, purok_id, first_name, last_name, birthdate, sex, civil_status, address_line, mobile, email, password, status) VALUES
    (9101, '00000000-0000-4000-8000-000000009101', 9101, 9101, 'NLQ', 'Resident', '1990-01-01', 'Male', 'Single', '123 NLQ Street', '091700009101', 'nlq-resident@example.test', 'fixture-password', 'approved'),
    (9102, '00000000-0000-4000-8000-000000009102', 9102, 9102, 'Foreign', 'Resident', '1990-01-01', 'Male', 'Single', '123 Foreign Street', '091700009102', 'foreign-resident@example.test', 'fixture-password', 'approved');

INSERT INTO residents (id, public_id, resident_registration_id, barangay_id, purok_id, resident_number, first_name, last_name, birthdate, sex, civil_status, address_line, mobile, email, status, approved_at) VALUES
    (9101, '10000000-0000-4000-8000-000000009101', 9101, 9101, 9101, 'NLQ-RES-0001', 'NLQ', 'Resident', '1990-01-01', 'Male', 'Single', '123 NLQ Street', '091700009101', 'nlq-resident@example.test', 'active', '2026-11-24 00:00:00'),
    (9102, '10000000-0000-4000-8000-000000009102', 9102, 9102, 9102, 'NLQ-RES-0002', 'Foreign', 'Resident', '1990-01-01', 'Male', 'Single', '123 Foreign Street', '091700009102', 'foreign-resident@example.test', 'active', '2026-11-24 00:00:00');

INSERT INTO resident_accounts (id, resident_id, password, is_active) VALUES
    (9101, 9101, 'fixture-password', 1),
    (9102, 9102, 'fixture-password', 1);

INSERT INTO certificates (id, barangay_id, code, name, description, fee, requirements, is_active) VALUES
    (9101, 9101, 'FREE-CERT', 'Free Certificate', 'Local free certificate.', 0, '["Valid ID"]', 1),
    (9102, 9101, 'UNSPECIFIED-CERT', 'Unspecified Certificate', 'Local certificate without a published fee.', NULL, '["Proof of residence"]', 1),
    (9103, 9102, 'FOREIGN-FEE-CERT', 'Hidden Foreign Fee Certificate', 'Foreign barangay certificate.', 9999, '["Foreign requirement"]', 1);

INSERT INTO citizen_charter_services (id, barangay_id, current_version_id, title, category, description, display_order, status, is_published, published_at, archived_at) VALUES
    (9101, 9101, NULL, 'Residency Certification Service', 'Certificates', 'Published requirements for residency certification.', 1, 'published', 1, '2026-11-24 00:00:00', NULL),
    (9102, 9101, NULL, 'Unrelated Local Service', 'Permits', 'Local service not presented as a certificate match.', 2, 'published', 1, '2026-11-24 00:00:00', NULL),
    (9103, 9102, NULL, 'Foreign Certificate Service', 'Certificates', 'Hidden Foreign Charter Service.', 1, 'published', 1, '2026-11-24 00:00:00', NULL);

INSERT INTO citizen_charter_versions (id, service_id, barangay_id, version_number, disk, path, original_name, mime_type, file_size, sha256, status, published_at, archived_at) VALUES
    (9101, 9101, 9101, 1, 'local', 'citizen-charter/fixture-9101.pdf', 'residency-charter.pdf', 'application/pdf', 1024, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'published', '2026-11-24 00:00:00', NULL),
    (9102, 9102, 9101, 1, 'local', 'citizen-charter/fixture-9102.pdf', 'local-service-charter.pdf', 'application/pdf', 1024, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'published', '2026-11-24 00:00:00', NULL),
    (9103, 9103, 9102, 1, 'local', 'citizen-charter/fixture-9103.pdf', 'foreign-charter.pdf', 'application/pdf', 1024, 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc', 'published', '2026-11-24 00:00:00', NULL);

UPDATE citizen_charter_services SET current_version_id = 9101 WHERE id = 9101;
UPDATE citizen_charter_services SET current_version_id = 9102 WHERE id = 9102;
UPDATE citizen_charter_services SET current_version_id = 9103 WHERE id = 9103;
