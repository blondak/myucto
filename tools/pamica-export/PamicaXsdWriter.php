<?php

declare(strict_types=1);

/**
 * Obecný zapisovač XML řízený XSD. Strom elementů a atributů se čte přímo
 * z oficiálních schémat ČSSZ (xs:import / xs:include, ref, pojmenované typy,
 * rozšíření typů, skupiny), hodnoty dodává objekt PamicaValueResolver.
 *
 * Nic se nedopočítává ani nevymýšlí: element nebo atribut, ke kterému resolver
 * hodnotu nemá, se do XML nezapíše (výsledek pak může neprojít validací, a to je
 * záměr - chybějící údaj je vidět, ne zastřený).
 *
 * V datovém slovníku ČSSZ nese popis elementu "(ID 10xxx)"; z něj se skládá mapa
 * ID atributu -> element (viz PamicaXsdModel::idMap()).
 */

final class PamicaXsdSimple
{
    /** @param list<string> $enum */
    public function __construct(
        public string $base,
        public array $enum = [],
    ) {}
}

final class PamicaXsdNode
{
    /**
     * @param list<int> $ids
     * @param list<PamicaXsdParticle> $particles
     * @param list<PamicaXsdNode> $attrs
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $ns,
        public int $min,
        public ?int $max,
        public array $ids,
        public ?PamicaXsdSimple $simple,
        public array $particles,
        public array $attrs,
        /** @var array<int,string> */
        public array $labels = [],
        public string $doc = '',
        public bool $alt = false,
    ) {}

    public function isLeaf(): bool
    {
        return $this->particles === [] && $this->attrs === [];
    }
}

final class PamicaXsdParticle
{
    /** @param list<PamicaXsdParticle> $items */
    public function __construct(
        public string $kind,
        public int $min,
        public ?int $max,
        public array $items = [],
        public ?PamicaXsdNode $node = null,
    ) {}
}

interface PamicaValueResolver
{
    /** Surová hodnota pro element (path = názvy elementů od kořene) nebo atribut (poslední segment "@název"). */
    public function value(PamicaXsdNode $node, array $path): ?string;

    /** Instance opakovaného elementu, nebo null = jedna instance se stejným resolverem. @return list<PamicaValueResolver>|null */
    public function instances(PamicaXsdNode $node, array $path): ?array;

    /** Název zvolené větve xs:choice, nebo null = vezme se první neprázdná. @param list<string> $names */
    public function choice(array $names, array $path): ?string;
}

final class PamicaXsdModel
{
    public const XS = 'http://www.w3.org/2001/XMLSchema';

    /** @var array<string,DOMDocument> */
    private array $docs = [];
    /** @var array<string,array<string,DOMElement>> */
    private array $reg = ['element' => [], 'complexType' => [], 'simpleType' => [], 'group' => [], 'attributeGroup' => [], 'attribute' => []];
    /** @var array<int,array{particles:list<PamicaXsdParticle>,attrs:list<PamicaXsdNode>,simple:?PamicaXsdSimple}> */
    private array $contentCache = [];
    /** @var array<int,int> počet dosavadních použití sdíleného uzlu v jednom průchodu stromem */
    private array $siteCounters = [];

    public function load(string $file, string $inheritNs = ''): void
    {
        $real = realpath($file);
        if ($real === false) {
            throw new RuntimeException("XSD $file neexistuje.");
        }
        if (isset($this->docs[$real])) {
            return;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->load($real);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new RuntimeException("XSD $real nejde přečíst.");
        }
        $this->docs[$real] = $doc;
        $root = $doc->documentElement;
        $tns = $root->getAttribute('targetNamespace');
        if ($tns === '') {
            $tns = $inheritNs;
        }
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== self::XS) {
                continue;
            }
            $kind = $child->localName;
            if ($kind === 'import' || $kind === 'include') {
                $location = $child->getAttribute('schemaLocation');
                if ($location !== '') {
                    $this->load(dirname($real) . DIRECTORY_SEPARATOR . $location, $kind === 'include' ? $tns : '');
                }
                continue;
            }
            if (isset($this->reg[$kind]) && $child->getAttribute('name') !== '') {
                $this->reg[$kind][$tns . '|' . $child->getAttribute('name')] = $child;
            }
        }
    }

    public function targetNamespace(DOMElement $e): string
    {
        return $e->ownerDocument->documentElement->getAttribute('targetNamespace');
    }

    /** @return array<string,string> prefix => ns z kořene schématu */
    public function rootPrefixes(string $file): array
    {
        $doc = $this->docs[realpath($file)] ?? null;
        $out = [];
        if ($doc === null) {
            return $out;
        }
        foreach ((new DOMXPath($doc))->query('namespace::*', $doc->documentElement) ?: [] as $declaration) {
            if ($declaration->localName !== 'xml' && $declaration->localName !== 'xmlns') {
                $out[$declaration->localName] = $declaration->nodeValue;
            }
        }
        return $out;
    }

    public function globalElement(string $ns, string $local): PamicaXsdNode
    {
        $e = $this->reg['element'][$ns . '|' . $local] ?? null;
        if ($e === null) {
            throw new RuntimeException("Globální element $local ($ns) ve schématu není.");
        }
        $this->siteCounters = [];
        return $this->contextualize($this->elementNode($e, true));
    }

    /** @return array{0:string,1:string} */
    private function qname(DOMElement $ctx, string $qname): array
    {
        if (str_contains($qname, ':')) {
            [$prefix, $local] = explode(':', $qname, 2);
            return [(string) $ctx->lookupNamespaceURI($prefix), $local];
        }
        return [(string) $ctx->lookupNamespaceURI(null), $qname];
    }

    /** @return array{0:int,1:?int} */
    private function occurs(DOMElement $e): array
    {
        $min = $e->hasAttribute('minOccurs') ? (int) $e->getAttribute('minOccurs') : 1;
        $maxRaw = $e->hasAttribute('maxOccurs') ? $e->getAttribute('maxOccurs') : '1';
        return [$min, $maxRaw === 'unbounded' ? null : (int) $maxRaw];
    }

    /**
     * ID atributů datového slovníku z popisu deklarace. Závorkové "(ID n)", "(ID n/m)", "(ID n,m)" mají přednost;
     * element sdílený více kontexty (typ osoby apod.) jich má víc, každé se štítkem řádku popisu.
     *
     * @return array{ids:list<int>,labels:array<int,string>,doc:string,alt:bool}
     */
    private function docInfo(DOMElement $e): array
    {
        $text = '';
        foreach ($e->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'annotation') {
                $text .= "\n" . $child->textContent;
            }
        }
        $text = trim($text);
        $doc = trim((string) strtok($text, "\n"));
        $ids = [];
        $labels = [];
        $alt = false;
        $previous = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $before = $previous;
            $previous = $line;
            if (preg_match_all('/\(ID\s+(\d{1,6}(?:\s*[\/,]\s*\d{1,6})*)\)/u', $line, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }
            foreach ($matches as $m) {
                $alt = $alt || str_contains($m[1], '/');
                foreach (preg_split('/\s*[\/,]\s*/', trim($m[1])) ?: [] as $id) {
                    $ids[] = (int) $id;
                    // Štítek ID je řádek "DS: oddíl > pod-oddíl"; stojí-li ID až na řádku s popisem pod ním, je štítkem řádek předchozí.
                    $own = mb_substr($line, 0, (int) mb_strpos($line, $m[0]));
                    $source = !str_starts_with(ltrim($line), 'DS') && str_starts_with(ltrim($before), 'DS') ? $before : $own;
                    $labels[(int) $id] = trim(preg_replace('/DS:\s*/u', '', $source) ?? '');
                }
            }
        }
        if ($ids === [] && preg_match('/\bID\s+(\d{1,6}(?:\s*[\/,]\s*\d{1,6})*)/u', $text, $m) === 1) {
            foreach (preg_split('/\s*[\/,]\s*/', trim($m[1])) ?: [] as $id) {
                $ids[] = (int) $id;
                $labels[(int) $id] = '';
            }
        }
        return ['ids' => array_values(array_unique($ids)), 'labels' => $labels, 'doc' => $doc, 'alt' => $alt];
    }

    private function elementNode(DOMElement $e, bool $global = false): PamicaXsdNode
    {
        [$min, $max] = $this->occurs($e);
        $info = $this->docInfo($e);
        if ($e->hasAttribute('ref')) {
            [$ns, $local] = $this->qname($e, $e->getAttribute('ref'));
            $target = $this->reg['element'][$ns . '|' . $local] ?? throw new RuntimeException("Reference na element $local ($ns) nejde rozřešit.");
            $n = $this->elementNode($target, true);
            return new PamicaXsdNode('element', $n->name, $n->ns, $min, $max, $info['ids'] ?: $n->ids, $n->simple, $n->particles, $n->attrs, $info['ids'] ? $info['labels'] : $n->labels, $info['doc'] ?: $n->doc, $info['ids'] ? $info['alt'] : $n->alt);
        }
        $qualified = $global
            || $e->getAttribute('form') === 'qualified'
            || ($e->getAttribute('form') === '' && $e->ownerDocument->documentElement->getAttribute('elementFormDefault') === 'qualified');
        $ns = $qualified ? $this->targetNamespace($e) : '';
        [$simple, $particles, $attrs] = $this->typeOf($e);
        return new PamicaXsdNode('element', $e->getAttribute('name'), $ns, $min, $max, $info['ids'], $simple, $particles, $attrs, $info['labels'], $info['doc'], $info['alt']);
    }

    /**
     * Strom s kontextově zúženými ID. Element sdílený víc kontexty (typ osoby jako dítě, jiná osoba, manželka,
     * v měsíčním i ročním oddílu) nese víc ID; vybere se to, jehož štítek v popisu nejvíc sedí na popisy
     * a názvy předků. Při shodě skóre zůstanou všechna (např. rodné číslo / EČP).
     */
    public function contextualize(PamicaXsdNode $node, array $ancestors = [], int $depth = 0): PamicaXsdNode
    {
        $context = [...$ancestors, $node->doc, $node->name];
        $ids = $node->ids;
        if (count($ids) > 1 && !$node->alt) {
            $scores = [];
            foreach ($ids as $id) {
                $scores[$id] = self::contextScore($node->labels[$id] ?? '', $ancestors);
            }
            $best = max($scores);
            $ids = array_values(array_filter($ids, fn (int $id): bool => $scores[$id] === $best));
            if (count($ids) > 1) {
                // Shoda skóre: ID jsou určená pro jednotlivá místa použití v pořadí, v jakém se sdílený uzel ve schématu potkává.
                $key = spl_object_id($node);
                $site = $this->siteCounters[$key] ?? 0;
                $this->siteCounters[$key] = $site + 1;
                $ids = [$ids[min($site, count($ids) - 1)]];
            }
        }
        $attrs = array_map(fn (PamicaXsdNode $a): PamicaXsdNode => $this->contextualize($a, $context, $depth + 1), $node->attrs);
        $particles = $depth > 60 ? $node->particles : array_map(fn (PamicaXsdParticle $p): PamicaXsdParticle => $this->contextualizeParticle($p, $context, $depth + 1), $node->particles);
        return new PamicaXsdNode($node->kind, $node->name, $node->ns, $node->min, $node->max, $ids, $node->simple, $particles, $attrs, $node->labels, $node->doc, $node->alt);
    }

    private function contextualizeParticle(PamicaXsdParticle $p, array $context, int $depth): PamicaXsdParticle
    {
        return new PamicaXsdParticle(
            $p->kind,
            $p->min,
            $p->max,
            array_map(fn (PamicaXsdParticle $i): PamicaXsdParticle => $this->contextualizeParticle($i, $context, $depth), $p->items),
            $p->node === null ? null : $this->contextualize($p->node, $context, $depth),
        );
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        $text = strtr(mb_strtolower($text), [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r',
            'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
        $text = str_replace(['deti', 'detem', 'dite', 'ditete'], 'dite', $text);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $text) ?: [] as $word) {
            if (strlen($word) >= 4) {
                $out[] = $word;
            }
        }
        return array_values(array_unique($out));
    }

    /** Slova se shodují při společném předponě dlouhé aspoň 4 znaky, kterému chybí nejvýš jeden znak kratšího slova (sleva/slevy, zaměstnanců/zaměstnanec). */
    private static function similar(string $a, string $b): bool
    {
        $prefix = 0;
        $max = min(strlen($a), strlen($b));
        while ($prefix < $max && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        return $prefix >= 4 && $prefix >= $max - 1;
    }

    /** @param list<string> $ancestors */
    private static function contextScore(string $label, array $ancestors): float
    {
        $labelStems = self::stems($label);
        $contextStems = self::stems(implode(' ', array_map(fn (string $s): string => preg_replace('/([a-z])([A-Z])/u', '$1 $2', $s) ?? $s, $ancestors)));
        $hit = 0;
        foreach ($labelStems as $word) {
            foreach ($contextStems as $other) {
                if (self::similar($word, $other)) {
                    $hit++;
                    break;
                }
            }
        }
        return $hit - 0.3 * (count($labelStems) - $hit);
    }

    private function attributeNode(DOMElement $e): PamicaXsdNode
    {
        $source = $e;
        if ($e->hasAttribute('ref')) {
            [$ns, $local] = $this->qname($e, $e->getAttribute('ref'));
            $source = $this->reg['attribute'][$ns . '|' . $local] ?? $e;
        }
        $simple = null;
        if ($source->hasAttribute('type')) {
            [$tn, $tl] = $this->qname($source, $source->getAttribute('type'));
            $simple = $this->simpleByName($tn, $tl);
        } else {
            foreach ($source->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'simpleType') {
                    $simple = $this->simpleFromType($child);
                }
            }
        }
        $info = $this->docInfo($e);
        if ($info['ids'] === [] && $source !== $e) {
            $info = $this->docInfo($source);
        }
        return new PamicaXsdNode(
            'attribute',
            $source->getAttribute('name'),
            '',
            $e->getAttribute('use') === 'required' ? 1 : 0,
            1,
            $info['ids'],
            $simple ?? new PamicaXsdSimple('string'),
            [],
            [],
            $info['labels'],
            $info['doc'],
            $info['alt'],
        );
    }

    /** @return array{0:?PamicaXsdSimple,1:list<PamicaXsdParticle>,2:list<PamicaXsdNode>} */
    private function typeOf(DOMElement $e): array
    {
        if ($e->hasAttribute('type')) {
            [$tn, $tl] = $this->qname($e, $e->getAttribute('type'));
            if ($tn === self::XS) {
                return [new PamicaXsdSimple($tl), [], []];
            }
            if (isset($this->reg['complexType'][$tn . '|' . $tl])) {
                $c = $this->complexContent($this->reg['complexType'][$tn . '|' . $tl]);
                return [$c['simple'], $c['particles'], $c['attrs']];
            }
            return [$this->simpleByName($tn, $tl), [], []];
        }
        foreach ($e->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'complexType') {
                $c = $this->complexContent($child);
                return [$c['simple'], $c['particles'], $c['attrs']];
            }
            if ($child->localName === 'simpleType') {
                return [$this->simpleFromType($child), [], []];
            }
        }
        return [new PamicaXsdSimple('string'), [], []];
    }

    /** @return array{particles:list<PamicaXsdParticle>,attrs:list<PamicaXsdNode>,simple:?PamicaXsdSimple} */
    private function complexContent(DOMElement $ct): array
    {
        $key = spl_object_id($ct);
        if (isset($this->contentCache[$key])) {
            return $this->contentCache[$key];
        }
        $particles = [];
        $attrs = [];
        $simple = null;
        foreach ($ct->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'sequence':
                case 'choice':
                case 'all':
                case 'group':
                    $p = $this->particle($child);
                    if ($p !== null) {
                        $particles[] = $p;
                    }
                    break;
                case 'attribute':
                    $attrs[] = $this->attributeNode($child);
                    break;
                case 'attributeGroup':
                    array_push($attrs, ...$this->attributeGroup($child));
                    break;
                case 'complexContent':
                case 'simpleContent':
                    foreach ($child->childNodes as $derivation) {
                        if (!$derivation instanceof DOMElement) {
                            continue;
                        }
                        $isExtension = $derivation->localName === 'extension';
                        [$bn, $bl] = $this->qname($derivation, $derivation->getAttribute('base'));
                        if ($child->localName === 'simpleContent') {
                            if (isset($this->reg['complexType'][$bn . '|' . $bl])) {
                                $base = $this->complexContent($this->reg['complexType'][$bn . '|' . $bl]);
                                $simple = $base['simple'];
                                array_push($attrs, ...$base['attrs']);
                            } else {
                                $simple = $this->simpleByName($bn, $bl);
                            }
                        } elseif ($isExtension && isset($this->reg['complexType'][$bn . '|' . $bl])) {
                            $base = $this->complexContent($this->reg['complexType'][$bn . '|' . $bl]);
                            array_push($particles, ...$base['particles']);
                            array_push($attrs, ...$base['attrs']);
                        }
                        foreach ($derivation->childNodes as $inner) {
                            if (!$inner instanceof DOMElement) {
                                continue;
                            }
                            if (in_array($inner->localName, ['sequence', 'choice', 'all', 'group'], true)) {
                                $p = $this->particle($inner);
                                if ($p !== null) {
                                    $particles[] = $p;
                                }
                            } elseif ($inner->localName === 'attribute') {
                                $attrs[] = $this->attributeNode($inner);
                            } elseif ($inner->localName === 'attributeGroup') {
                                array_push($attrs, ...$this->attributeGroup($inner));
                            }
                        }
                    }
                    break;
            }
        }
        return $this->contentCache[$key] = ['particles' => $particles, 'attrs' => $attrs, 'simple' => $simple];
    }

    /** @return list<PamicaXsdNode> */
    private function attributeGroup(DOMElement $ref): array
    {
        [$ns, $local] = $this->qname($ref, $ref->getAttribute('ref'));
        $group = $this->reg['attributeGroup'][$ns . '|' . $local] ?? null;
        $out = [];
        if ($group === null) {
            return $out;
        }
        foreach ($group->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'attribute') {
                $out[] = $this->attributeNode($child);
            }
        }
        return $out;
    }

    private function particle(DOMElement $p): ?PamicaXsdParticle
    {
        [$min, $max] = $this->occurs($p);
        if ($p->localName === 'group') {
            [$ns, $local] = $this->qname($p, $p->getAttribute('ref'));
            $group = $this->reg['group'][$ns . '|' . $local] ?? null;
            if ($group === null) {
                return null;
            }
            foreach ($group->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, ['sequence', 'choice', 'all'], true)) {
                    return $this->particle($child);
                }
            }
            return null;
        }
        $items = [];
        foreach ($p->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'element':
                    [$cmin, $cmax] = $this->occurs($child);
                    $items[] = new PamicaXsdParticle('el', $cmin, $cmax, [], $this->elementNode($child));
                    break;
                case 'sequence':
                case 'choice':
                case 'all':
                case 'group':
                    $sub = $this->particle($child);
                    if ($sub !== null) {
                        $items[] = $sub;
                    }
                    break;
            }
        }
        return new PamicaXsdParticle($p->localName === 'choice' ? 'choice' : 'seq', $min, $max, $items);
    }

    private function simpleByName(string $ns, string $local): PamicaXsdSimple
    {
        if ($ns === self::XS) {
            return new PamicaXsdSimple($local);
        }
        $st = $this->reg['simpleType'][$ns . '|' . $local] ?? null;
        return $st === null ? new PamicaXsdSimple('string') : $this->simpleFromType($st);
    }

    private function simpleFromType(DOMElement $st): PamicaXsdSimple
    {
        foreach ($st->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'annotation') {
                continue;
            }
            if ($child->localName !== 'restriction') {
                return new PamicaXsdSimple('string');
            }
            $inner = null;
            if ($child->hasAttribute('base')) {
                [$bn, $bl] = $this->qname($child, $child->getAttribute('base'));
                $inner = $this->simpleByName($bn, $bl);
            } else {
                foreach ($child->childNodes as $c2) {
                    if ($c2 instanceof DOMElement && $c2->localName === 'simpleType') {
                        $inner = $this->simpleFromType($c2);
                    }
                }
            }
            $enum = $inner->enum ?? [];
            $own = [];
            foreach ($child->childNodes as $facet) {
                if ($facet instanceof DOMElement && $facet->localName === 'enumeration') {
                    $own[] = $facet->getAttribute('value');
                }
            }
            return new PamicaXsdSimple($inner->base ?? 'string', $own !== [] ? $own : $enum);
        }
        return new PamicaXsdSimple('string');
    }

    /**
     * Mapa ID atributu datového slovníku -> seznam cest "a.b.c" (atributy s "@") ve stromu kořene.
     *
     * @return array<int,list<string>>
     */
    public function idMap(PamicaXsdNode $root): array
    {
        $out = [];
        $this->collect($root, [], $out, []);
        return $out;
    }

    /** @param list<string> $path @param array<int,list<string>> $out @param array<string,bool> $stack */
    private function collect(PamicaXsdNode $node, array $path, array &$out, array $stack): void
    {
        $segment = ($node->kind === 'attribute' ? '@' : '') . $node->name;
        $here = [...$path, $segment];
        foreach ($node->ids as $id) {
            $out[$id][] = implode('.', $here);
        }
        $guard = $segment . '#' . count($node->particles) . '#' . count($node->attrs);
        if (isset($stack[$guard]) && count($here) > 40) {
            return;
        }
        $stack[$guard] = true;
        foreach ($node->attrs as $attr) {
            $this->collect($attr, $here, $out, $stack);
        }
        foreach ($node->particles as $particle) {
            $this->collectParticle($particle, $here, $out, $stack);
        }
    }

    /** @param list<string> $path @param array<int,list<string>> $out @param array<string,bool> $stack */
    private function collectParticle(PamicaXsdParticle $p, array $path, array &$out, array $stack): void
    {
        if ($p->node !== null) {
            $this->collect($p->node, $path, $out, $stack);
        }
        foreach ($p->items as $item) {
            $this->collectParticle($item, $path, $out, $stack);
        }
    }
}

final class PamicaXsdWriter
{
    /**
     * @param array<string,string> $prefixes ns => prefix (výchozí jmenný prostor kořene = '')
     */
    public function __construct(private readonly array $prefixes) {}

    /** @param array<string,string> $rootAttributes */
    public function write(PamicaXsdNode $root, PamicaValueResolver $resolver, array $rootAttributes = []): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $element = $this->createElement($doc, $root);
        $doc->appendChild($element);
        foreach ($this->prefixes as $ns => $prefix) {
            if ($prefix !== '') {
                $element->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $ns);
            }
        }
        $this->fill($doc, $element, $root, $resolver, [$root->name]);
        foreach ($rootAttributes as $name => $value) {
            $element->setAttribute($name, $value);
        }
        return $doc;
    }

    private function createElement(DOMDocument $doc, PamicaXsdNode $node): DOMElement
    {
        $prefix = $this->prefixes[$node->ns] ?? ($node->ns === '' ? '' : 'ns');
        $qualified = ($prefix !== '' ? $prefix . ':' : '') . $node->name;
        return $node->ns === '' ? $doc->createElement($node->name) : $doc->createElementNS($node->ns, $qualified);
    }

    /** @param list<string> $path */
    private function fill(DOMDocument $doc, DOMElement $el, PamicaXsdNode $node, PamicaValueResolver $r, array $path): void
    {
        foreach ($node->attrs as $attr) {
            $raw = $r->value($attr, [...$path, '@' . $attr->name]);
            if ($raw !== null && $raw !== '') {
                $el->setAttribute($attr->name, self::convert($raw, $attr->simple));
            }
        }
        if ($node->particles !== []) {
            foreach ($node->particles as $particle) {
                $this->emitParticle($doc, $el, $particle, $r, $path);
            }
        } elseif ($node->simple !== null) {
            $raw = $r->value($node, $path);
            if ($raw !== null && $raw !== '') {
                $el->appendChild($doc->createTextNode(self::convert($raw, $node->simple)));
            }
        }
    }

    /** @param list<string> $path */
    private function emitParticle(DOMDocument $doc, DOMElement $parent, PamicaXsdParticle $p, PamicaValueResolver $r, array $path): void
    {
        if ($p->kind === 'el') {
            $this->emitElement($doc, $parent, $p->node, $r, $path);
            return;
        }
        if ($p->kind === 'seq') {
            foreach ($p->items as $item) {
                $this->emitParticle($doc, $parent, $item, $r, $path);
            }
            return;
        }
        $names = [];
        foreach ($p->items as $item) {
            if ($item->node !== null) {
                $names[] = $item->node->name;
            }
        }
        $chosen = $r->choice($names, $path);
        foreach ($p->items as $item) {
            if ($chosen !== null && ($item->node === null || $item->node->name !== $chosen)) {
                continue;
            }
            $probe = $doc->createDocumentFragment();
            $holder = $doc->createElement('holder');
            $this->emitParticle($doc, $holder, $item, $r, $path);
            if ($holder->hasChildNodes()) {
                while ($holder->firstChild !== null) {
                    $parent->appendChild($holder->firstChild);
                }
                return;
            }
            unset($probe);
        }
    }

    /** @param list<string> $path */
    private function emitElement(DOMDocument $doc, DOMElement $parent, PamicaXsdNode $node, PamicaValueResolver $r, array $path): void
    {
        $here = [...$path, $node->name];
        $repeats = $node->max === null || $node->max > 1;
        $instances = $repeats ? ($r->instances($node, $here) ?? [$r]) : [$r];
        $emitted = 0;
        foreach ($instances as $instance) {
            if ($node->max !== null && $emitted >= $node->max) {
                break;
            }
            $el = $this->createElement($doc, $node);
            $this->fill($doc, $el, $node, $instance, $here);
            if ($el->hasChildNodes() || $el->hasAttributes()) {
                $parent->appendChild($el);
                $emitted++;
            }
        }
    }

    public static function convert(string $value, ?PamicaXsdSimple $simple): string
    {
        $base = $simple->base ?? 'string';
        $enum = $simple->enum ?? [];
        // PAMICA drží texty s koncovými mezerami; mezery na okrajích hodnoty se v XML neposílají.
        $value = trim($value);
        if ($enum !== [] && in_array($value, $enum, true)) {
            return $value;
        }
        if (in_array('A', $enum, true) && in_array('N', $enum, true)) {
            $lower = strtolower($value);
            if ($lower === 'true') {
                return 'A';
            }
            if ($lower === 'false') {
                return 'N';
            }
        }
        switch ($base) {
            case 'date':
                if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', trim($value), $m) === 1) {
                    return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
                }
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) === 1) {
                    return $m[1];
                }
                return trim($value);
            case 'dateTime':
                if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})$/', trim($value), $m) === 1) {
                    return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', (int) $m[3], (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], (int) $m[6]);
                }
                return trim($value);
            case 'boolean':
                $v = strtolower(trim($value));
                if (in_array($v, ['a', 'y', '1', 'true', 'ano'], true)) {
                    return 'true';
                }
                if (in_array($v, ['n', '0', 'false', 'ne'], true)) {
                    return 'false';
                }
                return trim($value);
            case 'decimal':
            case 'double':
            case 'float':
                return self::number($value);
            case 'int':
            case 'integer':
            case 'short':
            case 'long':
            case 'byte':
            case 'nonNegativeInteger':
            case 'positiveInteger':
            case 'unsignedInt':
            case 'unsignedShort':
                $n = self::number($value);
                return $n;
            default:
                return $value;
        }
    }

    private static function number(string $value): string
    {
        $v = trim($value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $v) !== 1) {
            return $v;
        }
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }
        return $v === '' || $v === '-' ? '0' : $v;
    }
}
