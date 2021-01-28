<?php
/**
 * 2018-2021 Alma SAS
 *
 * THE MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and
 * to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
 * Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @author    Alma SAS <contact@getalma.eu>
 * @copyright 2018-2021 Alma SAS
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

namespace Tests\Alma\SyliusPaymentPlugin\App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

class StartCommand extends Command
{
    protected static $defaultName = 'alma:start';
    /**
     * @var KernelInterface
     */
    private $appKernel;

    public function __construct(KernelInterface $appKernel, string $name = null)
    {
        parent::__construct($name);
        $this->appKernel = $appKernel;
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Starting development servers for Alma plugin");

        $io->section("Starting MySQL server on port 3306");

        $dbDataDir = "{$this->appKernel->getProjectDir()}/var/db_data";
        $process = new Process(explode(' ', "docker run --name alma_mysql_test_server -d -eMYSQL_ROOT_PASSWORD=root -p3306:3306 -v{$dbDataDir}:/var/lib/mysql mysql:5.7"));
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error($process->getErrorOutput());
            return $process->getExitCode();
        }

        $io->success("Done!");

        $io->section("Starting built-in web server");
        $command = $this->getApplication()->find('server:run');
        $command->run(new ArrayInput(['-d' => 'public']), $output);

        return 0;
    }
}
