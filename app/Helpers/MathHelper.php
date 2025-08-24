<?php
function renderLatexToImage($latex, $outputDir = null)
    {
        $latex = str_replace(['$', '\\'], ['','\\\\'], $latex); // sanitize LaTeX for standalone document
        $outputDir = $outputDir ?? storage_path('app/public/math_images');

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $hash = md5($latex); // unique filename
        $pngPath = "$outputDir/$hash.png";

        if (file_exists($pngPath)) {
            return $pngPath; // already rendered
        }

        // Create temporary .tex file
        $texFile = tempnam(sys_get_temp_dir(), 'latex') . '.tex';
        $texContent = <<<EOT
    \\documentclass[preview]{standalone}
    \\usepackage{amsmath,amssymb}
    \\begin{document}
    $latex
    \\end{document}
    EOT;

        file_put_contents($texFile, $texContent);

        // Compile to PDF using MiKTeX pdflatex
        $cmd = "pdflatex -interaction=nonstopmode -output-directory=" . sys_get_temp_dir() . " $texFile";
        shell_exec($cmd);

        $pdfFile = str_replace('.tex', '.pdf', $texFile);

        // Convert PDF → PNG using ImageMagick (convert command)
        $cmdConvert = "convert -density 150 $pdfFile $pngPath"; // 150 dpi
        shell_exec($cmdConvert);

        return $pngPath;
    }