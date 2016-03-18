<?php

namespace Defr\SiteScanner\Command\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SiteMapCommand
 * @package Defr\SiteScanner
 * @author  Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class SiteMapCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('scanner:sitemap')
            ->setDescription('Scan sitemap')
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'Enter sitemap.xml URL address.'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Specify output file name (CSV or XLSX).'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit number of parsed pages (for testing purposes).'
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

        $siteMapGrabber = new SiteMapGrabber($input->getArgument('url'));

        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $formattedLine = $formatter->formatBlock(
            sprintf("%s (%s) [%s]", $this->getApplication()->getName(), $this->getApplication()->getVersion(), $siteMapGrabber->getDomain()),
            "fg=black;bg=green",
            true
        );

        $output->writeln("");
        $output->writeln($formattedLine);
        $output->writeln("");

        $output->writeln("Downloading sitemap...");
        $siteMapGrabber->download();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "Sitemap downloaded."));

        $output->writeln("Parsing sitemap.xml...");
        $siteMapGrabber->parsePagesFromXml();
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "XML parsed."));

        $output->writeln("Parsing all pages in sitemap...");
        $output->writeln("");

        $limit = $input->getOption("limit");
        if ($limit) {
            $siteMapGrabber->limitPages($limit);
        }

        $containing = $input->getOption("containing");
        if ($containing) {
            $siteMapGrabber->deleteUrlNotContaining($containing);
        }

        $progress = new ProgressBar($output, count($siteMapGrabber->getPages()));
        $progress->setMessage("Preparing...");
        $progress->setFormat("%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%\n%message%");
        $progress->start();

        foreach ($siteMapGrabber->getPages() as $i => $page) {
            $progress->setMessage(sprintf("Parsing %s", $page['loc']));
            $siteMapGrabber->parsePage($page);
            $progress->advance();
        }
        $progress->finish();
        $output->writeln("");
        $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "All pages parsed."));

        $output->writeln("Generating " . strtoupper($extension) . "...");
        $siteMapGrabber->sortPagesEntities();

        switch (strtolower($extension)) {
            case "csv":
                $fp = fopen($file, 'w');
                fputcsv($fp, ["URL", "Title", "H1", "Description", "Keywords", "Error message"], ";", "\"");
                foreach ($siteMapGrabber->getPagesEntities() as $page) {
                    fputcsv($fp, [$page->getUrl(), $page->getTitle(), $page->getH1(), $page->getDescription(), $page->getKeywords(), $page->getError()], ";", "\"");
                }
                $output->writeln($formatter->formatSection("\xE2\x9C\x94 OK", "CSV generated."));

                fclose($fp);
                break;
            case "xlsx":
                $objPHPExcel = new \PHPExcel();
                $objPHPExcel->getProperties()->setLastModifiedBy(sprintf("%s (%s)", $this->getApplication()->getName(), $this->getApplication()->getVersion()));
                $objPHPExcel->getActiveSheet()
                    ->setTitle("Site Scanner")
                    ->fromArray(["URL", "Title", "H1", "Description", "Keywords", "Error message"], null, 'A1');
                foreach ($siteMapGrabber->getPagesEntities() as $row => $page) {
                    $objPHPExcel->getActiveSheet()->fromArray([$page->getUrl(), $page->getTitle(), $page->getH1(), $page->getDescription(), $page->getKeywords(), $page->getError()], null, "A" . ($row + 2));
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