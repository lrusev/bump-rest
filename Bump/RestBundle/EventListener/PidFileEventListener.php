<?php

namespace Bump\RestBundle\EventListener;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Bridge\Monolog\Logger;

class PidFileEventListener
{
    protected $pidFile;
    protected $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function onConsoleCommand(ConsoleCommandEvent $event)
    {
        $inputDefinition = $event->getCommand()->getApplication()->getDefinition();

        // add the option to the application's input definition
        $inputDefinition->addOption(
            new InputOption('lockdir', null, InputOption::VALUE_OPTIONAL, 'The location of the directory to PID file that should be created for this process', null)
        );

        // merge the application's input definition
        $event->getCommand()->mergeApplicationDefinition();
        // the input object will read the actual arguments from $_SERVER['argv']
        $input = new ArgvInput();

        // bind the application's input definition to it
        $input->bind($event->getCommand()->getDefinition());

        $lockdir = $input->getOption('lockdir');
        if (!is_null($lockdir)) {
            if (!file_exists($lockdir)) {
                throw new \InvalidArgumentException("Lock Dir {$lockdir} does not exists.");
            }

            if (!is_dir($lockdir)) {
                throw new \InvalidArgumentException("Expected {$lockdir} to be directory.");
            }

            if (!is_writable($lockdir)) {
                throw new \InvalidArgumentException("Lock Dir {$lockdir} does not writable, please check permissions.");
            }

            $this->pidFile = $this->getPidFilename($event->getCommand(), $lockdir);
            if (file_exists($this->pidFile)) {
                $lockingPID = file_get_contents($this->pidFile);
                $pids = explode("\n", trim(`ps -e | awk '{print $1}'`));

                if (empty($pids)) {
                    $message = "Can't fetch pid list, and shootdown";
                    $this->getLogger()->error($message);
                    $event->getOutput()->writeln($message);
                    exit;
                }

                if (in_array($lockingPID, $pids)) {
                    $message = 'This command is already running in another process.';
                    $event->getOutput()->writeln($message);
                    $this->logger->warn('Command execution ['.$event->getCommand()->getName().']:'.$message);
                    exit;
                } else {
                    $message = "Previous command died abruptly.";
                    $event->getOutput()->writeln($message);
                    $this->logger->warn('Command execution ['.$event->getCommand()->getName().']:'.$message);
                }
            }

            file_put_contents($this->pidFile, getmypid());

            $pidFile = $this->pidFile;
            register_shutdown_function(
                function () use ($pidFile) {
                    unlink($pidFile);
                }
            );
        }
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event)
    {
        if ($this->pidFile) {
            if (file_exists($this->pidFile)) {
                unlink($this->pidFile);
            }
        }
    }

    protected function getPidFilename(Command $command, $lockdir)
    {
        $name = strtolower($command->getName());
        $name = preg_replace(array('/[^a-z]/i', '/[_]+/'), '_', $name);

        return rtrim($lockdir, '/').'/'.$name.'.lock';
    }
}
