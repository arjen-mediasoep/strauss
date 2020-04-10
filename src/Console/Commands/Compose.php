<?php

namespace CoenJacobs\Mozart\Console\Commands;

use CoenJacobs\Mozart\Composer\Autoload\Classmap;
use CoenJacobs\Mozart\Composer\Autoload\NamespaceAutoloader;
use CoenJacobs\Mozart\Composer\Package;
use CoenJacobs\Mozart\Mover;
use CoenJacobs\Mozart\Replacer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Compose extends Command
{
    /** @var Mover */
    private $mover;

    /** @var Replacer */
    private $replacer;

    /** @var string */
    private $workingDir;

    /** @var */
    private $config;

    protected function configure()
    {
        $this->setName('compose');
        $this->setDescription('Composes all dependencies as a package inside a WordPress plugin.');
        $this->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $workingDir = getcwd();
        $this->workingDir = $workingDir;

        $config = json_decode(file_get_contents($workingDir . '/composer.json'));
        $config = $config->extra->mozart;
        $this->config = $config;

        $this->mover = new Mover($workingDir, $config);
        $this->replacer = new Replacer($workingDir, $config);

        $packages = $this->findPackages($config->packages);

        $this->movePackages($packages);
        $this->replacePackages($packages);

        foreach ($packages as $package) {
            $this->replacer->replaceParentPackage($package, null);
        }

	    $this->generateClassmapAutoloader();

        return 0;
    }

    /**
     * @param $workingDir
     * @param $config
     * @param array $packages
     */
    protected function movePackages($packages)
    {
        $this->mover->deleteTargetDirs();

        foreach ($packages as $package) {
            $this->movePackage($package);
        }
    }

    /**
     * @param $workingDir
     * @param $config
     * @param array $packages
     */
    protected function replacePackages($packages)
    {
        foreach ($packages as $package) {
            $this->replacePackage($package);
        }
    }

    /**
     * Move all the packages over, one by one, starting on the deepest level of dependencies.
     */
    public function movePackage($package)
    {
        if (! empty($package->dependencies)) {
            foreach ($package->dependencies as $dependency) {
                $this->movePackage($dependency);
            }
        }

        $this->mover->movePackage($package);
    }

    /**
     * Replace contents of all the packages, one by one, starting on the deepest level of dependencies.
     */
    public function replacePackage($package)
    {
        if (! empty($package->dependencies)) {
            foreach ($package->dependencies as $dependency) {
                $this->replacePackage($dependency);
            }
        }

        $this->replacer->replacePackage($package);
    }

    /**
     * Loops through all dependencies and their dependencies and so on...
     * will eventually return a list of all packages required by the full tree.
     */
    private function findPackages($slugs)
    {
        $packages = [];

        foreach ($slugs as $package_slug) {
            $packageDir = $this->workingDir . '/vendor/' . $package_slug .'/';

            if (! is_dir($packageDir)) {
                continue;
            }

            $package = new Package($packageDir);
            $package->findAutoloaders();

            $config = json_decode(file_get_contents($packageDir . 'composer.json'));

            $dependencies = [];
            if (isset($config->require)) {
                $dependencies = array_keys((array)$config->require);
            }

            $package->dependencies = $this->findPackages($dependencies);
            $packages[] = $package;
        }

        return $packages;
    }


	/**
	 * Write a classmap to file.
	 */
	private function generateClassmapAutoloader() {

		if (!isset($this->config->classmap_output)) {
			return;
		}

		$classmap = $this->replacer->classmap;

		if( !isset($this->config->classmap_output->filename)) {
			return;
		}
		$output_filename = $this->config->classmap_output->filename;

		$relative_path = isset( $this->config->classmap_output->relative_path ) ? $this->config->classmap_output->relative_path : null;

		$output = "<?php\n\n";

		$output .= "// autoload_classmap.php @generated by Mozart\n\n";

		$output .= "return array(\n";

		foreach($classmap as $class => $filepath) {

			if ( !is_null($relative_path) && substr($filepath, 0, strlen($relative_path)) == $relative_path) {
				$filepath = substr($filepath, strlen($relative_path));
			}

			$output .= "    '{$class}' => '{$filepath}',\n";
		}

		$output .= ");";

		file_put_contents( $output_filename, $output );

	}
}
