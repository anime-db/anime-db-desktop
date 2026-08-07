<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Service\ProxyConfigProvider;
use PHPUnit\Framework\TestCase;

final class ProxyConfigProviderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-proxy-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testGetSettingsReturnsNoneWhenFileIsMissing(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsReturnsNoneWhenProxyKeyIsMissing(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsReturnsNoneWhenProxyKeyIsNotAnArray(): void
    {
        file_put_contents($this->configPath, json_encode(['proxy' => 'bogus']));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsReturnsNoneWhenFileIsNotValidJson(): void
    {
        file_put_contents($this->configPath, '{not json');

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsReturnsNoneWhenModeIsNotRecognized(): void
    {
        file_put_contents($this->configPath, json_encode(['proxy' => ['mode' => 'bogus']]));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsDowngradesManualModeWithoutHostToNone(): void
    {
        file_put_contents($this->configPath, json_encode([
            'proxy' => ['mode' => 'manual', 'protocol' => 'http', 'port' => 8080],
        ]));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsDowngradesManualModeWithInvalidPortToNone(): void
    {
        file_put_contents($this->configPath, json_encode([
            'proxy' => ['mode' => 'manual', 'protocol' => 'http', 'host' => '127.0.0.1', 'port' => 70000],
        ]));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyMode::None, $provider->getSettings()->mode);
    }

    public function testGetSettingsReadsFullManualConfig(): void
    {
        file_put_contents($this->configPath, json_encode([
            'proxy' => [
                'mode' => 'manual',
                'protocol' => 'socks5',
                'host' => '127.0.0.1',
                'port' => 1080,
                'username' => 'user',
                'password' => 'pass',
            ],
        ]));

        $provider = new ProxyConfigProvider($this->configPath);
        $settings = $provider->getSettings();

        $this->assertSame(ProxyMode::Manual, $settings->mode);
        $this->assertSame(ProxyProtocol::Socks5, $settings->protocol);
        $this->assertSame('127.0.0.1', $settings->host);
        $this->assertSame(1080, $settings->port);
        $this->assertSame('user', $settings->username);
        $this->assertSame('pass', $settings->password);
    }

    public function testGetSettingsDefaultsProtocolToSocks5WhenMissing(): void
    {
        file_put_contents($this->configPath, json_encode([
            'proxy' => ['mode' => 'manual', 'host' => '127.0.0.1', 'port' => 1080],
        ]));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyProtocol::Socks5, $provider->getSettings()->protocol);
    }

    public function testGetSettingsFallsBackToSocks5WhenProtocolIsNotRecognized(): void
    {
        file_put_contents($this->configPath, json_encode([
            'proxy' => ['mode' => 'manual', 'protocol' => 'bogus', 'host' => '127.0.0.1', 'port' => 1080],
        ]));

        $provider = new ProxyConfigProvider($this->configPath);

        $this->assertSame(ProxyProtocol::Socks5, $provider->getSettings()->protocol);
    }

    public function testSetSettingsCreatesConfigFileWhenMissing(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);

        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080));

        $this->assertSame(ProxyMode::Manual, $provider->getSettings()->mode);
    }

    public function testSetSettingsPreservesOtherKeys(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc', 'locale' => 'ru']));

        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080));

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('ru', $data['locale']);
        $this->assertSame('manual', $data['proxy']['mode']);
    }

    public function testSetSettingsRoundTripsCredentials(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, '127.0.0.1', 1080, 'user', 'pass'));

        $settings = $provider->getSettings();

        $this->assertSame('user', $settings->username);
        $this->assertSame('pass', $settings->password);
    }

    public function testGetHttpClientOptionsReturnsNullProxyWhenModeIsNone(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);

        $options = $provider->getHttpClientOptions();

        $this->assertNull($options['proxy']);
        $this->assertSame('localhost,127.0.0.1,::1', $options['no_proxy']);
    }

    public function testGetHttpClientOptionsBuildsProxyUrlForManualMode(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080, 'user', 'pass'));

        $options = $provider->getHttpClientOptions();

        $this->assertSame('http://user:pass@127.0.0.1:8080', $options['proxy']);
        $this->assertSame('localhost,127.0.0.1,::1', $options['no_proxy']);
    }

    public function testGetHttpClientOptionsAlwaysIncludesLoopbackInNoProxy(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Socks5, 'proxy.local', 1080));

        $noProxy = $provider->getHttpClientOptions()['no_proxy'];

        $this->assertStringContainsString('localhost', $noProxy);
        $this->assertStringContainsString('127.0.0.1', $noProxy);
        $this->assertStringContainsString('::1', $noProxy);
    }

    public function testWriteConfigLeavesNoTemporaryFileBehind(): void
    {
        $provider = new ProxyConfigProvider($this->configPath);
        $provider->setSettings(new ProxySettings(ProxyMode::Manual, ProxyProtocol::Http, '127.0.0.1', 8080));

        $this->assertFileDoesNotExist($this->configPath.'.tmp');
    }
}
