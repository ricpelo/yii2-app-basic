#!/usr/bin/env php
<?php

function fallo($mensaje)
{
    echo "\033[1;31m* Error: $mensaje\033[0m\n";
    return 1;
}

if (file_exists('vendor')) {
    require 'vendor/autoload.php';
} elseif (file_exists('../vendor')) {
    require '../vendor/autoload.php';
}

$issues = isset($argv[1]) && $argv[1] === '-i';
$check = isset($argv[1]) && $argv[1] === '-c';

if ($issues) {
    echo "\nSe ha indicado la opción '\033[1;28m-i\033[0m'. Se actualizarán las incidencias\n";
    echo "en GitHub y se anotarán los enlaces correspondientes en los\n";
    echo "archivos '\033[1;28mrequisitos.md\033[0m' y '\033[1;28mrequisitos.xls\033[0m' (en cambio, si este\n";
    echo "último contiene ya anotadas las incidencias creadas, no se\n";
    echo "volverán a crear ni se modificarán en GitHub).\n\n";
    echo "\033[1;31m*** ESTE PROCESO ES IRREVERSIBLE Y NO SE PUEDE INTERRUMPIR ***\033[0m\n\n";
    echo "\033[1;28m¿Deseas continuar? (s/N): \033[0m";
    $sn = '';
    fscanf(STDIN, '%s', $sn);
    if ($sn !== 's' && $sn !== 'S') {
        exit(1);
    }
}

\PhpOffice\PhpSpreadsheet\Settings::setLocale('es');
$objPHPExcel = \PhpOffice\PhpSpreadsheet\IOFactory::load('requisitos.xls');
$objWorksheet = $objPHPExcel->getSheet(0);
$highestRow = $objWorksheet->getHighestDataRow(); // e.g. 10
$highestColumn = $objWorksheet->getHighestDataColumn(); // e.g 'F'
$highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn); // e.g. 5

echo "\033[1;28m# Comprobando archivo requisitos.xls...\033[0m\n";

for ($fallo = 0, $row = 2; $row <= $highestRow; $row++) {
    echo '(' . ($row - 1) . '/' . ($highestRow - 1) . ') ';
    $codigo      = $objWorksheet->getCell("A$row")->getValue();
    $prioridad   = $objWorksheet->getCell("D$row")->getValue();
    $tipo        = $objWorksheet->getCell("E$row")->getValue();
    $complejidad = $objWorksheet->getCell("F$row")->getValue();
    $entrega     = $objWorksheet->getCell("G$row")->getValue();
    $incidencia  = $objWorksheet->getCell("H$row")->getValue();

    if (!preg_match('/R[1-9]\d*/u', $codigo)) {
        $fallo = fallo("El código '$codigo' es incorrecto (celda A$row).\n  Debe empezar por R y seguir con un número que no empiece por 0.");
    }
    if (!in_array($prioridad, ['Mínimo', 'Importante', 'Opcional'])) {
        $fallo = fallo("Error: La prioridad '$prioridad' es incorrecta (celda D$row).");
    }
    if (!in_array($tipo, ['Funcional', 'Técnico', 'Información'])) {
        $fallo = fallo("El tipo '$tipo' es incorrecto (celda E$row).");
    }
    if (!in_array($complejidad, ['Fácil', 'Media', 'Difícil'])) {
        $fallo = fallo("La complejidad '$complejidad' es incorrecta (celda F$row).");
    }
    if (!in_array($entrega, ['v1', 'v2', 'v3'])) {
        $fallo = fallo("La entrega '$entrega' es incorrecta (celda G$row).");
    }
    if ($incidencia != '' && !ctype_digit((string) $incidencia)) {
        $fallo = fallo("La incidencia '$incidencia' es incorrecta (celda H$row).");
    }
}

if ($fallo == 0) {
    echo "\n\033[1;28m# No se han encontrado errores en el archivo 'requisitos.xls'.\033[0m\n";
    if ($check) {
        exit(0);
    }
} else {
    exit($fallo);
}

if ($issues) {
    try {
        $client = new \Github\Client();
        $client->authenticate(getenv('GITHUB_TOKEN'), null, \Github\Client::AUTH_HTTP_TOKEN);
        $login = $client->currentUser()->show()['login'];
        $repo = trim(`basename -s .git $(git remote get-url origin)`);
    } catch (\Github\Exception\RuntimeException $e) {
        fallo("No se ha podido encontrar el repositorio en GitHub.");
        exit(1);
    }

    $milestones = $client->api('issue')->milestones()->all($login, $repo);
    $entregas = [1 => 'v1', 2 => 'v2', 3 => 'v3'];

    if (array_diff_assoc($entregas, array_column($milestones, 'title', 'number')) !== []) {
        foreach ($milestones as $m) {
            echo "# Eliminando el hito {$m['title']}...\n";
            $client->api('issue')->milestones()->remove($login, $repo, $m['number']);
        }
        foreach ($entregas as $e) {
            echo "# Creando el hito $e...\n";
            $client->api('issue')->milestones()->create($login, $repo, ['title' => $e]);
        }
    }

    $etiquetas = [
        'mínimo' => 'e99695',
        'importante' => '9370db',
        'opcional' => 'fef2c0',
        'fácil' => 'f9ca98',
        'media' => '93d8d7',
        'difícil' => 'b60205',
        'funcional' => 'd4c5f9',
        'técnico' => '006b75',
        'información' => '0052cc',
    ];

    $labels = array_column($client->api('issue')->labels()->all($login, $repo), 'name');

    foreach ($etiquetas as $name => $color) {
        if (in_array($name, $labels)) {
            echo "# Actualizando el color de la etiqueta $name...\n";
            $client->api('issue')->labels()->update($login, $repo, $name, $name, $color);
        } else {
            echo "# Creando la etiqueta $name...\n";
            $client->api('issue')->labels()->create($login, $repo, ['name' => $name, 'color' => $color]);
        }
    }

    $projects = $client->api('repo')->projects()->configure()->all($login, $repo);

    if (count($projects) == 0) {
        echo "# Creando el nuevo proyecto en GitHub Projects...";
        $project = $client->api('repo')->projects()->configure()->create($login, $repo, ['name' => 'Proyecto']);
        echo " #{$project['number']}\n";
    } else {
        $project = $projects[0];
        echo "# Usando el proyecto #{$project['number']} ya existente...\n";
    }

    $columns = $client->api('repo')->projects()->columns()->configure()->all($project['id']);
    if (count($columns) == 0) {
        echo "# Creando columnas en el proyecto...\n";
        $column = $client->api('repo')->projects()->columns()->configure()->create($project['id'], ['name' => 'To Do']);
        $client->api('repo')->projects()->columns()->configure()->create($project['id'], ['name' => 'In Progress']);
        $client->api('repo')->projects()->columns()->configure()->create($project['id'], ['name' => 'Done']);
    } else {
        $column = $columns[0];
        echo "# Usando columnas ya existentes en el proyecto...\n";
    }
}

$requisitos = "\n# Catálogo de requisitos\n\n";
$resumen = "\n## Cuadro resumen\n\n"
         . '| **Requisito** | **Prioridad** | **Tipo** | **Complejidad** | **Entrega** |'
         . ($issues ? ' **Incidencia** |' : '') . "\n"
         . '| :------------ | :-----------: | :------: | :-------------: | :---------: |'
         . ($issues ? ' :------------: |' : '') . "\n";

echo "\033[1;28m# Leyendo archivo requisitos.xls...\033[0m\n";

for ($row = 2; $row <= $highestRow; $row++) {
    if ($issues && ($row - 1) % 10 === 0) {
        echo '# Deteniendo la ejecución por 5 segundos para no exceder el límite de tasa...';
        sleep(5);
        echo "\n";
    }
    echo '(' . ($row - 1) . '/' . ($highestRow - 1) . ') ';
    $codigo      = $objWorksheet->getCell("A$row")->getValue();
    $corta       = $objWorksheet->getCell("B$row")->getValue();
    $cortaMd     = $corta;
    $corta       = preg_replace('/`/u', '\`', $corta);
    $larga       = $objWorksheet->getCell("C$row")->getValue();
    $largaMd     = preg_replace('/\n/u', ' ', $larga);
    $larga       = preg_replace('/`/u', '\`', $larga);
    $prioridad   = $objWorksheet->getCell("D$row")->getValue();
    $tipo        = $objWorksheet->getCell("E$row")->getValue();
    $complejidad = $objWorksheet->getCell("F$row")->getValue();
    $entrega     = $objWorksheet->getCell("G$row")->getValue();
    $incidencia  = $objWorksheet->getCell("H$row")->getValue();

    if ($issues) {
        if ($incidencia === null) {
            echo "Generando incidencia para $codigo en GitHub...";
            $issue = $client->api('issue')->create($login, $repo, [
                'title' => $corta,
                'body' => $larga,
                'assignee' => $login,
                'milestone' => mb_substr($entrega, 1, 1),
                'labels' => [
                    mb_strtolower($prioridad),
                    mb_strtolower($tipo),
                    mb_strtolower($complejidad)
                ],
                'projects' => "$login/$repo/{$project['number']}",
            ]);
            $incidencia = $issue['number'];
            $link = "https://github.com/$repo/issues/$incidencia";
            $objWorksheet->setCellValue("H$row", $incidencia);
            $objWorksheet->getCell("H$row")->getHyperlink()->setUrl($link);
            $client->api('repo')->projects()->columns()->cards()->configure()->create($column['id'], ['content_type' => 'Issue', 'content_id' => $issue['id']]);
            echo " #$incidencia\n";
        } else {
            echo "El requisito $codigo ya tiene asociada la incidencia #$incidencia.\n";
            $issue = $client->api('issue')->show($login, $repo, $incidencia);
        }
        $link = "https://github.com/$login/$repo/issues/$incidencia";
    }

    $incidencia = $issues ? "| **Incidencia**  | [$incidencia]($link) |" : '';
    $requisitos .= "| **$codigo**     | **$cortaMd**         |\n"
                 . "| --------------: | :------------------- |\n"
                 . "| **Descripción** | $largaMd             |\n"
                 . "| **Prioridad**   | $prioridad           |\n"
                 . "| **Tipo**        | $tipo                |\n"
                 . "| **Complejidad** | $complejidad         |\n"
                 . "| **Entrega**     | $entrega             |\n"
                 . $incidencia . "\n\n";

    $resumen .= "| (**$codigo**) $cortaMd | $prioridad | $tipo | $complejidad | $entrega | $incidencia\n";
}

if (!$issues) {
    echo "\n";
}

echo "\033[1;28m# Generando archivo requisitos.md...\033[0m\n";
file_put_contents('requisitos.md', $requisitos . $resumen, LOCK_EX);

if ($issues) {
    echo "\033[1;28m# Actualizando archivo requisitos.xls...\033[0m\n";
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($objPHPExcel);
    $writer->save('requisitos.xls');
    echo "\033[1;31m# No olvides ir a https://github.com/$login/$repo/projects/{$project['number']}\n";
    echo "  para configurar la automatización de las columnas del proyecto en GitHub.\033[0m\n";
}
