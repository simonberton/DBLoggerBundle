<?php
namespace Acilia\Bundle\DBLoggerBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\LockHandler;
use Symfony\Component\Finder\Finder;
use Exception;
use DateTime;
use Doctrine\DBAL\Connection;

class ArchiveCommand extends Command
{
    const ARCHIVE_DAYS = 30;
    protected static $defaultName = 'acilia:dblogger:archive';
    private $connection = null;
    private $config;

    public function __construct($config = null)
    {
        $this->config = $config;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Moves the log to it\'s archive')
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_OPTIONAL,
                sprintf('Number of days to preserve, default %s days', self::ARCHIVE_DAYS),
                self::ARCHIVE_DAYS
            )
            ->addOption(
                'purge',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Number of days to preserve on the archive, purge older, default none'
            )
        ;
    }

    /**
     * If pdo data is set we use it, if not doctrine is.
     */
    private function getConnection()
    {
        if ($this->connection === null) {
            $usePdo = false;
            if (isset($this->config['pdo'])) {
                if (!isset($this->config['pdo']['url']) || !isset($this->config['pdo']['user']) || !isset($this->config['pdo']['password'])) {
                    throw new Exception('pdo configuration missing or not completed, (url, user and password must be set).');
                } else {
                    $usePdo = true;
                }
            }
        
            if ($usePdo) {
                $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
                $this->connection = new \PDO($this->config['pdo']['url'], $this->config['pdo']['user'], $this->config['pdo']['password'], $options);
            } else {
                $this->connection = $this->getContainer()->get('doctrine')->getManager()->getConnection();
            }
        } 

        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $days = $input->getOption('days');
            $purge = $input->getOption('purge');

            if (!is_numeric($days)) {
                throw new Exception('Days is not a valid number');
            }

            if ($days > $purge) {
                throw new Exception(sprintf('Purge days (%s) must be greater than Archive days (%s)', $purge, $days));
            }

            // Calculate now
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            $now->modify('-' . $days . ' days');

            // Get connection
            $connection = $this->getConnection();

            // Archive logs
            $output->write('Archiving logs... ');
            $stmt = $connection->prepare('INSERT INTO log_archive SELECT * FROM log WHERE log_datetime < ?');
            $stmt->bindValue(1, $now->format('Y-m-d'));
            $stmt->execute();
            $output->writeln('OK');

            // Delete logs
            $output->write('Deleting logs... ');
            $stmt = $connection->prepare('DELETE FROM log WHERE log_datetime < ?');
            $stmt->bindValue(1, $now->format('Y-m-d'));
            $stmt->execute();
            $output->writeln('OK');

            if (is_numeric($purge)) {
                $now = new DateTime();
                $now->setTime(0, 0, 0);
                $now->modify('-' . $purge . ' days');

                // Delete Archived logs
                $output->write('Purge Archived logs... ');
                $stmt = $connection->prepare('DELETE FROM log_archive WHERE log_datetime < ?');
                $stmt->bindValue(1, $now->format('Y-m-d'));
                $stmt->execute();
                $output->writeln('OK');
            }

            return 0;
        } catch (Exception $e) {
            $output->writeln(sprintf('ERROR: %s', $e->getMessage()));
            return 1;
        }
    }
}
