USE donorconnect;

INSERT INTO institutions (name, institution_type, region, town, address)
SELECT 'Eswatini National Blood Service', 'blood_service', 'Hhohho', 'Mbabane', 'Mbabane, Eswatini'
WHERE NOT EXISTS (SELECT 1 FROM institutions WHERE name = 'Eswatini National Blood Service');
