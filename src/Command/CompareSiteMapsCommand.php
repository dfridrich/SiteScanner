<?php

namespace Defr\SiteScanner;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CompareSitemapsCommand
 * @package Defr\SiteScanner
 * @author  Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class CompareSitemapsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('scanner:compare-sitemaps')
            ->setDescription('Compares sitemaps of 2 websites (useful for new vs. old website comparision)')
            ->addArgument(
                'masterSitemapUrl',
                InputArgument::REQUIRED,
                'Master sitemap.xml address.'
            )
            ->addArgument(
                'slaveSitemapUrl',
                InputArgument::REQUIRED,
                'Enter sitemap.xml URL address of website which must contains all URLs from master sitemap.'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Specify output file name (CSV or XLSX).'
            )
            ->addOption(
                'containing',
                null,
                InputOption::VALUE_OPTIONAL,
                'String which must be present in URL address.'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file');
        $extension = pathinfo($file)['extension'];

        if (!in_array($extension, ["csv", "xlsx"])) {
            throw new \InvalidArgumentException(sprintf("Extension %s is not implemented.", $extension));
        }

        $masterGrabber = new SiteMapGrabber($input->getArgument('masterSitemapUrl'));
        $slaveGrabber = new SiteMapGrabber($input->getArgument('slaveSitemapUrl'));

        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $formattedLine = $formatter->formatBlock(
            sprintf("%s (%s) [%s vs. %s]", $this->getApplication()->getName(), $this->getApplication()->getVersion(), $masterGrabber->getDomain(), $slaveGrabber->getDomain()),
            "fg=black;bg=green",
            true
        );

        $output->writeln("");
        $output->writeln($formattedLine);
        $output->writeln("");

        $output->writeln("Downloading sitemap from slave website...");
        $slaveGrabber->download();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "Sitemap from slave website downloaded."));

        $output->writeln("Downloading sitemap from master website...");
        $masterGrabber->download();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "Sitemap from master website downloaded."));

        $output->writeln("Parsing slave sitemap.xml...");
        $slaveGrabber->parsePagesFromXml();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "Slave website XML parsed."));

        $output->writeln("Parsing master sitemap.xml...");
        $masterGrabber->parsePagesFromXml();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "Master website XML parsed."));

        $containing = $input->getOption("containing");
        if ($containing) {
            $slaveGrabber->deleteUrlNotContaining($containing);
            $masterGrabber->deleteUrlNotContaining($containing);
        }

        $output->writeln("Comparing...");

        $masterPaths = $masterGrabber->getUrls(true);
        $slavePaths = $slaveGrabber->getUrls(true);

        $missingUrls = array_diff($masterPaths, $slavePaths);
        sort($missingUrls);

        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "All pages compared."));

        $output->writeln("Generating " . strtoupper($extension) . "...");

        switch (strtolower($extension)) {
            case "csv":
                $fp = fopen($file, 'w');
                fputcsv($fp, ["Path", "Master URL", "Slave URL should be"], ";", "\"");
                foreach ($missingUrls as $missingUrl) {
                    fputcsv($fp, [$missingUrl, "http://" . $masterGrabber->getDomain() . $missingUrl, "http://" . $slaveGrabber->getDomain() . $missingUrl], ";", "\"");

                }
                $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "CSV generated."));

                fclose($fp);
                break;
            case "xlsx":
                $objPHPExcel = new \PHPExcel();
                $objPHPExcel->getProperties()->setLastModifiedBy(sprintf("%s (%s)", $this->getApplication()->getName(), $this->getApplication()->getVersion()));
                $objPHPExcel->getActiveSheet()
                    ->setTitle("Site Scanner")
                    ->fromArray(["Path", "Master URL", "Slave URL should be"], null, 'A1');
                foreach ($missingUrls as $row => $missingUrl) {
                    $objPHPExcel->getActiveSheet()->fromArray([$missingUrl, "http://" . $masterGrabber->getDomain() . $missingUrl, "http://" . $slaveGrabber->getDomain() . $missingUrl], null, "A" . ($row + 2));
                }
                for ($col = 'A'; $col !== 'G'; $col++) {
                    $objPHPExcel->getActiveSheet()
                        ->getColumnDimension($col)
                        ->setAutoSize(true);
                }
                $objPHPExcel->getActiveSheet()
                    ->getStyle('A1:F1')
                    ->getFill()
                    ->setFillType(\PHPExcel_Style_Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFFF00');
                $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
                $objWriter->save($file);
                break;
        }

        $formattedLine = $formatter->formatBlock(
            sprintf("%s generated to file %s", strtoupper($extension), $input->getArgument('file')),
            "fg=black;bg=white",
            true
        );

        $output->writeln("");
        $output->writeln($formattedLine);
        $output->writeln("");

    }

}