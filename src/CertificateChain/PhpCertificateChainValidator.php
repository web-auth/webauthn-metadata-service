<?php

declare(strict_types=1);

namespace Webauthn\MetadataService\CertificateChain;

use function array_slice;
use Assert\Assertion;
use function count;
use DateTimeZone;
use function in_array;
use InvalidArgumentException;
use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use const PHP_EOL;
use const PHP_URL_SCHEME;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;
use SpomkyLabs\Pki\ASN1\Type\Primitive\Integer;
use SpomkyLabs\Pki\ASN1\Type\UnspecifiedType;
use SpomkyLabs\Pki\CryptoEncoding\PEM;
use SpomkyLabs\Pki\X509\Certificate\Certificate;
use SpomkyLabs\Pki\X509\CertificationPath\CertificationPath;
use SpomkyLabs\Pki\X509\CertificationPath\PathValidation\PathValidationConfig;
use Throwable;

/**
 * @final
 */
class PhpCertificateChainValidator implements CertificateChainValidator
{
    private const MAX_VALIDATION_LENGTH = 5;

    private readonly Clock $clock;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        ?Clock $clock = null,
    ) {
        if ($clock === null) {
            $clock = new SystemClock(new DateTimeZone('UTC'));
        }
        $this->clock = $clock;
    }

    /**
     * @param string[] $untrustedCertificates
     * @param string[] $trustedCertificates
     */
    public function check(array $untrustedCertificates, array $trustedCertificates): void
    {
        $certificates = array_map(
            static fn (string $cert): Certificate => Certificate::fromPEM(PEM::fromString($cert)),
            array_merge(array_reverse($trustedCertificates), array_reverse($untrustedCertificates))
        );

        if (! $this->validateCertificates(...$certificates)) {
            throw new InvalidArgumentException('Unable to validate the certificate chain.');
        }

        $numCerts = count($certificates);
        for ($i = 1; $i < $numCerts; $i++) {
            if ($this->isRevoked($certificates[$i], ...array_slice($certificates, 0, $i))) {
                throw new RuntimeException('Unable to validate the certificate chain.');
            }
        }
    }

    public function isRevoked(Certificate $subject, Certificate ...$caCertificates): bool
    {
        try {
            $csn = $subject->tbsCertificate()
                ->serialNumber();
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Failed to parse certificate: %s', $e->getMessage()), 0, $e);
        }

        try {
            $urls = $this->getCrlUrlList($subject);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Failed to get CRL distribution points: ' . $e->getMessage(), 0, $e);
        }

        foreach ($urls as $url) {
            try {
                $revokedCertificates = $this->retrieveRevokedSerialNumbers($url);

                if (in_array($csn, $revokedCertificates, true)) {
                    return true;
                }
            } catch (Throwable $e) {
                throw new InvalidArgumentException(sprintf(
                    'Failed to retrieve CRL %s:' . PHP_EOL . '%s',
                    $url,
                    $e->getMessage()
                ), 0, $e);
            }
        }
        return false;
    }

    private function validateCertificates(Certificate ...$certificates): bool
    {
        try {
            $path = new CertificationPath(...$certificates);
            $config = new PathValidationConfig($this->clock->now(), self::MAX_VALIDATION_LENGTH);
            $path->validate($config);

            return true;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Failed to validate certificate: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return string[]
     */
    private function retrieveRevokedSerialNumbers(string $url): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $url);
            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() !== 200) {
                throw new InvalidArgumentException(sprintf('Failed to download CRL for certificate from %s', $url));
            }
            $crlData = $response->getBody()
                ->getContents();
            $crl = UnspecifiedType::fromDER($crlData)->asSequence();
            Assertion::count($crl, 3, 'Invalid CRL.');
            $tbsCertList = $crl->at(0)
                ->asSequence();
            Assertion::minCount($tbsCertList, 6, 'Invalid CRL.');
            $list = $tbsCertList->at(5)
                ->asSequence();

            return array_map(static function (UnspecifiedType $r): string {
                $sequence = $r->asSequence();
                Assertion::minCount($sequence, 1, 'Invalid CRL.');
                /** @var integer $sn */
                $sn = $sequence->at(0)
                    ->asInteger();

                return $sn->number();
            }, $list->elements());
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                'Failed to download CRL for certificate from %s',
                $url
            ), null, $e);
        }
    }

    /**
     * @return string[]
     */
    private function getCrlUrlList(Certificate $subject): array
    {
        try {
            $urls = [];

            $extensions = $subject->tbsCertificate()
                ->extensions();
            if ($extensions->hasCRLDistributionPoints()) {
                $crlDists = $extensions->crlDistributionPoints();
                foreach ($crlDists->distributionPoints() as $dist) {
                    $url = $dist->fullName()
                        ->names()
                        ->firstURI();
                    $scheme = parse_url($url, PHP_URL_SCHEME);
                    if (! in_array($scheme, ['http', 'https'], true)) {
                        continue;
                    }
                    $urls[] = $url;
                }
            }
            return $urls;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'Failed to get CRL distribution points from certificate: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
