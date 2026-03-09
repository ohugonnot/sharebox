<?php

use PHPUnit\Framework\TestCase;

class SlugTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, token TEXT UNIQUE)');
    }

    private function slug(string $name): string
    {
        return generate_slug($name, $this->db);
    }

    public function testFilmClassique(): void
    {
        $slug = $this->slug('Batman.Begins.2005.MULTI.2160p.mkv');
        $this->assertStringStartsWith('batman-begins-2005-', $slug);
    }

    public function testAccents(): void
    {
        $slug = $this->slug('Astérix.et.Obélix.FRENCH.1080p.mkv');
        $this->assertStringStartsWith('asterix-et-obelix-', $slug);
    }

    /**
     * @dataProvider fallbackVideProvider
     */
    public function testFallbackVide(string $input): void
    {
        $slug = $this->slug($input);
        $this->assertStringStartsWith('partage-', $slug);
    }

    public static function fallbackVideProvider(): array
    {
        return [
            'vide' => [''],
            'points' => ['...'],
            'only special chars' => ['---___...mkv'],
        ];
    }

    public function testTroncature(): void
    {
        $longName = str_repeat('abcdefghij', 10) . '.mkv';
        $slug = $this->slug($longName);
        // Le body (avant le suffixe -XXXX) doit faire <= 40 chars
        $body = substr($slug, 0, strrpos($slug, '-'));
        $this->assertLessThanOrEqual(40, strlen($body));
    }

    public function testCharsSpeciaux(): void
    {
        $slug = $this->slug('file (copy) [2].avi');
        $this->assertStringStartsWith('file-copy-2-', $slug);
    }

    public function testUnicite(): void
    {
        $slug1 = $this->slug('Batman.mkv');
        // Insérer le premier slug pour que le prochain appel le voie
        $this->db->prepare('INSERT INTO links (token) VALUES (:t)')->execute([':t' => $slug1]);
        $slug2 = $this->slug('Batman.mkv');
        $this->assertNotEquals($slug1, $slug2);
    }

    public function testAntiCollision(): void
    {
        // Pré-insérer un token
        $this->db->prepare('INSERT INTO links (token) VALUES (:t)')->execute([':t' => 'batman-aaaa']);
        $slug = $this->slug('Batman.mkv');
        $this->assertNotEquals('batman-aaaa', $slug);
    }

    public function testFormatValide(): void
    {
        $names = [
            'Batman.Begins.2005.mkv',
            'Astérix.et.Obélix.mkv',
            '',
            'file (copy) [2].avi',
            str_repeat('a', 100) . '.mkv',
        ];
        foreach ($names as $name) {
            $slug = $this->slug($name);
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9][a-z0-9-]{1,50}$/',
                $slug,
                "Slug invalide pour '$name': $slug"
            );
        }
    }
}
