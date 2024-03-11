<?php

namespace Yceruto\SelfUpdatePlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositorySet;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use ZipArchive;

class SelfUpdateCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('project:self-update')
            ->setDescription('Updates the project to the latest version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();

        if (null === $composer = $this->tryComposer(true, true)) {
            $io->writeError('Composer is not available.');

            return self::FAILURE;
        }

        $extra = $composer->getPackage()->getExtra();
        $packageName = $extra['self-update-plugin']['package'] ?? $composer->getPackage()->getName();
        $packageVersion = $extra['self-update-plugin']['require'] ?? 'dev-main';
        $packageDir = realpath(dirname(Factory::getComposerFile()));

        if (!$packageName || '__root__' === $packageName) {
            $io->writeError(
                'Unable to determine the package name. Please, add "extra.self-update-plugin.package" config to your composer.json file and try again.'
            );

            return self::FAILURE;
        }

        $io->writeError(
            sprintf(
                '<info>Updating the current project with the "%s" package, version "%s".</info>',
                $packageName,
                $packageVersion
            )
        );

        $package = $this->selectPackage($io, $packageName, $packageVersion);

        if (!$package) {
            $io->writeError('<error>The specified package was not found.</error>');

            return self::FAILURE;
        }

        $filePath = $composer->getArchiveManager()->archive($package, $package->getDistType(), $packageDir);

        $this->extractWithZipArchive($filePath, $packageDir);
        @unlink($filePath);

        $io->writeError('Project has been patched successfully!');

        return self::SUCCESS;
    }

    protected function selectPackage(
        IOInterface $io,
        string $packageName,
        ?string $version = null
    ): (BasePackage&CompletePackageInterface)|false {
        $io->writeError('<info>Searching for the specified package.</info>');

        if ($composer = $this->tryComposer()) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $repo = new CompositeRepository(
                array_merge([$localRepo], $composer->getRepositoryManager()->getRepositories())
            );
            $minStability = $composer->getPackage()->getMinimumStability();
        } else {
            $defaultRepos = RepositoryFactory::defaultReposWithDefaultManager($io);
            $io->writeError(
                'No composer.json found in the current directory, searching packages from '.implode(
                    ', ',
                    array_keys($defaultRepos)
                )
            );
            $repo = new CompositeRepository($defaultRepos);
            $minStability = 'stable';
        }

        if ($version !== null && Preg::isMatchStrictGroups('{@(stable|RC|beta|alpha|dev)$}i', $version, $match)) {
            $minStability = $match[1];
            $version = substr($version, 0, -strlen($match[0]));
        }

        $repoSet = new RepositorySet($minStability);
        $repoSet->addRepository($repo);
        $parser = new VersionParser();
        $constraint = $version !== null ? $parser->parseConstraints($version) : null;
        $packages = $repoSet->findPackages(strtolower($packageName), $constraint);

        if (count($packages) > 1) {
            $versionSelector = new VersionSelector($repoSet);
            $package = $versionSelector->findBestCandidate(strtolower($packageName), $version, $minStability);
            if ($package === false) {
                $package = reset($packages);
            }

            $io->writeError('<info>Found multiple matches, selected '.$package->getPrettyString().'.</info>');
            $io->writeError(
                'Alternatives were '.implode(', ', array_map(static function ($p): string {
                    return $p->getPrettyString();
                }, $packages)).'.'
            );
            $io->writeError('<comment>Please use a more specific constraint to pick a different package.</comment>');
        } elseif (count($packages) === 1) {
            $package = reset($packages);
            $io->writeError('<info>Found an exact match '.$package->getPrettyString().'.</info>');
        } else {
            $io->writeError('<error>Could not find a package matching '.$packageName.'.</error>');

            return false;
        }

        if (!$package instanceof CompletePackageInterface) {
            throw new \LogicException('Expected a CompletePackageInterface instance but found '.get_class($package));
        }
        if (!$package instanceof BasePackage) {
            throw new \LogicException('Expected a BasePackage instance but found '.get_class($package));
        }

        return $package;
    }

    /**
     * extract $file to $path with ZipArchive
     *
     * @param string $file File to extract
     * @param string $path Path where to extract file
     *
     * @phpstan-return PromiseInterface<void|null>
     * @throws Throwable
     */
    private function extractWithZipArchive(string $file, string $path): PromiseInterface
    {
        $zipArchive = new ZipArchive();

        try {
            if (!file_exists($file) || ($filesize = filesize($file)) === false || $filesize === 0) {
                $retval = -1;
            } else {
                $retval = $zipArchive->open($file);
            }
            if (true === $retval) {
                $extractResult = $zipArchive->extractTo($path);

                if (true === $extractResult) {
                    $zipArchive->close();

                    return \React\Promise\resolve(null);
                }

                $processError = new \RuntimeException(
                    rtrim(
                        "There was an error extracting the ZIP file, it is either corrupted or using an invalid format.\n"
                    )
                );
            } else {
                $processError = new \UnexpectedValueException(
                    rtrim($this->getErrorMessage($retval, $file)."\n"),
                    $retval
                );
            }
        } catch (\ErrorException $e) {
            $processError = new \RuntimeException(
                'The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): '.$e->getMessage(
                ), 0, $e
            );
        } catch (\Throwable $e) {
            $processError = $e;
        }

        throw $processError;
    }

    /**
     * Give a meaningful error message to the user.
     */
    protected function getErrorMessage(int $retval, string $file): string
    {
        switch ($retval) {
            case ZipArchive::ER_EXISTS:
                return sprintf("File '%s' already exists.", $file);
            case ZipArchive::ER_INCONS:
                return sprintf("Zip archive '%s' is inconsistent.", $file);
            case ZipArchive::ER_INVAL:
                return sprintf("Invalid argument (%s)", $file);
            case ZipArchive::ER_MEMORY:
                return sprintf("Malloc failure (%s)", $file);
            case ZipArchive::ER_NOENT:
                return sprintf("No such zip file: '%s'", $file);
            case ZipArchive::ER_NOZIP:
                return sprintf("'%s' is not a zip archive.", $file);
            case ZipArchive::ER_OPEN:
                return sprintf("Can't open zip file: %s", $file);
            case ZipArchive::ER_READ:
                return sprintf("Zip read error (%s)", $file);
            case ZipArchive::ER_SEEK:
                return sprintf("Zip seek error (%s)", $file);
            case -1:
                return sprintf("'%s' is a corrupted zip archive (0 bytes), try again.", $file);
            default:
                return sprintf("'%s' is not a valid zip archive, got error code: %s", $file, $retval);
        }
    }
}
