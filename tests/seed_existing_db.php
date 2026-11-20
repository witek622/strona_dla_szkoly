<?php
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/db.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTestDirectory($path) {
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $entryPath = $entry->getPathname();
        if ($entry->isDir()) {
            removeTestDirectory($entryPath);
        } else {
            unlink($entryPath);
        }
    }
    rmdir($path);
}

$testRoot = sys_get_temp_dir() . '/school-seed-migration-' . bin2hex(random_bytes(6));
$dataDir = $testRoot . '/private-data';
if (!mkdir($dataDir, 0700, true)) {
    throw new RuntimeException('Nie można utworzyć tymczasowego katalogu danych');
}
putenv('APP_DATA_DIR=' . $dataDir);

try {
    $legacy = new PDO('sqlite:' . $dataDir . '/dziennik.sqlite');
    $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacy->exec(SCHEMA);
    $legacy->exec("INSERT INTO classes(name) VALUES('Istniejąca klasa')");
    $legacy->exec("INSERT INTO students(first,last,class_id) VALUES('Jan','Zachowany',1)");
    $legacy = null;

    $pdo = db();
    $existingStudent = $pdo->query("SELECT COUNT(*) FROM students WHERE last='Zachowany'")->fetchColumn();
    check((int)$existingStudent === 1, 'Migracja usunęła istniejącego ucznia');

    $classId = (int)$pdo->query("SELECT id FROM classes WHERE name='Istniejąca klasa'")->fetchColumn();
    $studentCount = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE class_id=' . $classId)->fetchColumn();
    $scheduleCount = (int)$pdo->query('SELECT COUNT(*) FROM schedule sc JOIN assignments a ON a.id=sc.assignment_id WHERE a.class_id=' . $classId)->fetchColumn();
    $gradeCount = (int)$pdo->query('SELECT COUNT(*) FROM grades g JOIN students s ON s.id=g.student_id WHERE s.class_id=' . $classId)->fetchColumn();
    check($studentCount >= 3, 'Migracja nie dodała uczniów demo do istniejącej klasy');
    check($scheduleCount > 0, 'Migracja nie dodała planu do istniejącej klasy');
    check($gradeCount > 0, 'Migracja nie dodała ocen do istniejącej klasy');

    $counts = [];
    foreach (['students', 'assignments', 'schedule', 'lessons', 'grades', 'remarks', 'excuses'] as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    seedDemoFixture($pdo);
    seedDemoAllClasses($pdo);
    foreach ($counts as $table => $count) {
        check((int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $count, "Ponowne uruchomienie powieliło dane w tabeli $table");
    }
} finally {
    removeTestDirectory($testRoot);
    putenv('APP_DATA_DIR');
}

echo "Test migracji danych demo dla istniejącej bazy: OK\n";
