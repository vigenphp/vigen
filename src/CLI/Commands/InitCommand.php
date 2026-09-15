<?php

declare(strict_types=1);

namespace Vigen\CLI\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Vigen\Project\EnvWriter;
use Vigen\Project\Scaffolder;
use Vigen\Providers\ProviderModels;

#[AsCommand(name: 'init', description: 'Set up a new Vigen project: interface, AI provider, and model.')]
class InitCommand extends Command
{
    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $output->writeln('<info>VIGEN</info>');
        $output->writeln('Describe what you want. Vigen builds it.');
        $output->writeln('');

        // 1. Preferred chat interface.
        $interfaceQuestion = new ChoiceQuestion(
            'Choose chat preferred:',
            ['CLI', 'GUI'],
            0
        );
        $interface = $helper->ask($input, $output, $interfaceQuestion);

        // 2. Default AI provider.
        $providerLabels = [
            'ollama' => 'Ollama (Offline)',
            'openai' => 'OpenAI',
            'claude' => 'Claude',
            'gemini' => 'Gemini',
        ];
        $providerQuestion = new ChoiceQuestion(
            'Which default AI Provider:',
            array_values($providerLabels),
            0
        );
        $providerLabel = $helper->ask($input, $output, $providerQuestion);
        $provider = array_search($providerLabel, $providerLabels, true) ?: 'ollama';

        // 3. Model for the chosen provider.
        $models = ProviderModels::for($provider);
        $modelQuestion = new ChoiceQuestion('Which model to use:', $models, 0);
        $model = $helper->ask($input, $output, $modelQuestion);

        // 4. API key, if the provider needs one.
        $apiKey = null;
        $apiKeyEnvVar = ProviderModels::apiKeyEnvVar($provider);
        if ($apiKeyEnvVar !== null) {
            $keyQuestion = new Question("Enter your {$providerLabel} API key: ");
            $keyQuestion->setHidden(true);
            $apiKey = (string) $helper->ask($input, $output, $keyQuestion);
        }

        $output->writeln('');
        $output->writeln('> Creating your Vigen project...');

        $scaffolder = new Scaffolder($this->basePath);
        $created = $scaffolder->scaffold();
        foreach ($created as $dir) {
            $output->writeln("✓ {$dir}/");
        }

        $envValues = [
            'VIGEN_AI_PROVIDER' => $provider,
            'VIGEN_AI_MODEL' => $model,
            'VIGEN_CHAT_INTERFACE' => strtolower($interface),
        ];
        if ($apiKeyEnvVar !== null && $apiKey !== null && $apiKey !== '') {
            $envValues[$apiKeyEnvVar] = $apiKey;
        }

        (new EnvWriter($this->basePath))->write($envValues);
        $output->writeln('✓ .env configured');
        $output->writeln('');
        $output->writeln('<info>Vigen project ready.</info>');
        $output->writeln('');

        if (strtolower($interface) === 'gui') {
            $output->writeln('Run this to open the built-in GUI chatbox:');
            $output->writeln('  <comment>vigen serve</comment>');
        } else {
            $output->writeln('Start building by talking to your project:');
            $output->writeln('  <comment>vigen chat "Create a user management system"</comment>');
        }

        return Command::SUCCESS;
    }
}
