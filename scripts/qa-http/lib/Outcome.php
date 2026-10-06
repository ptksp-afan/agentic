<?php

namespace QaHttp;

/**
 * Hasil akhir sebuah AC selain PASS dibawa lewat exception, supaya skenario cukup memanggil
 * $t->eq(...), $t->skip(...), $t->blocked(...) tanpa mengurus alur sendiri.
 */
class AssertionFailed extends \RuntimeException
{
}

class Skipped extends \RuntimeException
{
}

class Blocked extends \RuntimeException
{
}

/** Config/infrastruktur rusak (API_URL mati, login QA gagal, berkas skenario salah): run dihentikan, exit 2. */
class InfraError extends \RuntimeException
{
}
