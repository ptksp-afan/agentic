<?php
/**
 * ED-1070 AC-10 "V5 tak terpengaruh" - dependensi V5 pada route V4 yang hilang saat modul V4 dimatikan.
 * Kode V5 memanggil route('select.files.download') (nama ini hanya ada di Modules/V4/Routes/Select/select.php:1242;
 * versi V5 bernama 'v5.select.files.download'). Hanya GET, tanpa data uji.
 */

require_once __DIR__ . '/qa_lib.php';

return [
    [
        'id' => 'AC-10-v5-deps',
        'title' => 'V5 tidak bergantung pada route V4: detail perusahaan (logo), foto barang, cetak/URL unduhan lampiran = bukan 500 sesudah V4 dimatikan',
        'run' => function ($t) {
            $s = $t->session();
            $seen = [];
            $list = q70_get($t, $s, 'api/v5/company/companies?pagination=10');
            $t->status([$list[0], $list[1]], 200, 'GET company/companies');
            $rows = $list[1]['result']['data'] ?? ($list[1]['result'] ?? []);
            $ids = [];
            foreach ((array) $rows as $row) {
                if (is_array($row) && ($row['id_company'] ?? $row['idCompany'] ?? null)) {
                    $ids[] = $row['id_company'] ?? $row['idCompany'];
                }
            }
            $withLogo = $t->db()->table('companies')->join('attachments', 'attachments.id_transaction', '=', 'companies.id_company')->count();
            $t->note('perusahaan ber-lampiran (logo): ' . $withLogo);
            $bad = [];
            foreach (array_slice($ids, 0, 3) as $id) {
                $r = q70_get($t, $s, 'api/v5/company/companies/' . rawurlencode($id));
                $seen[] = 'company/' . substr($id, -6) . '=' . $r[0];
                if ($r[0] >= 500) {
                    $bad[] = 'GET company/companies/{id} = ' . $r[0] . (strpos($r[2], 'select.files.download') !== false ? ' (Route [select.files.download] not defined)' : '');
                }
            }
            $r = q70_get($t, $s, 'api/v5/item/item-photos?pagination=10');
            $seen[] = 'item-photos=' . $r[0] . '/total ' . q70_total($r);
            if ($r[0] >= 500) {
                $bad[] = 'GET item/item-photos = ' . $r[0] . (strpos($r[2], 'select.files.download') !== false ? ' (Route [select.files.download] not defined)' : '');
            }
            $t->note(implode(' ; ', $seen));
            $t->eq($bad, [], 'tidak ada 500 karena route V4 hilang');
        },
    ],
    [
        'id' => 'AC-10-file-urls',
        'title' => 'D-3 (ronde 2): URL berkas buatan BE (logo perusahaan, Company::info, thumbnail barang, item request, lampiran Raptor, template OTP) = /api/v5/select/files/download dan resolve (200 + isi berkas sama), atau gagal persis seperti kode V4 bila berkas hilang di disk',
        'run' => function ($t) {
            $s = $t->session();
            $db = $t->db();
            $qaDb = (string) $t->conf('QA_DB');
            $notes = [];
            $v5 = '/api/v5/select/files/download?';

            // URL unduhan di respons (kunci apa pun, level mana pun)
            $collect = function ($node) use (&$collect) {
                $out = [];
                if (is_array($node)) {
                    foreach ($node as $v) {
                        $out = array_merge($out, $collect($v));
                    }
                } elseif (is_string($node) && strpos($node, 'files/download') !== false) {
                    $out[] = $node;
                }

                return $out;
            };
            $fileState = function ($url) use ($t, $db) {
                $q = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $id = $q['idAttachment'] ?? $q['id_attachment'] ?? null;
                $type = $q['transactionType'] ?? $q['transaction_type'] ?? null;
                if ($type !== null && $id === null) {
                    return ['default-thumbnail', null];
                }
                $row = $id === null ? null : $db->selectOne('select file_path from attachments where id_attachment = ?', [$id]);
                if ($row === null) {
                    return ['no-row', null];
                }
                $exists = $t->probe(function () use ($row) {
                    return \Illuminate\Support\Facades\Storage::exists($row->file_path);
                });

                return [$exists ? 'exists' : 'missing', $row->file_path];
            };
            $thumbSha = sha1_file(rtrim((string) $t->conf('BE_DIR'), '/') . '/public/images/thumbnail.png');
            // satu URL: v5, bukan v4; diunduh tanpa token (seperti <img>); 200 + isi = berkas, atau 500 NOT_FOUND_ATTACHMENT bila berkas hilang
            $check = function ($label, $url) use ($t, $v5, $fileState, $thumbSha, &$notes) {
                $t->true(strpos($url, '/api/v4/') === false, "$label: URL tidak menunjuk api/v4 ($url)");
                $t->true(strpos($url, $v5) === 0, "$label: URL diawali $v5 ($url)");
                list($state, $path) = $fileState($url);
                $f = $t->callFile(null, 'GET', ltrim($url, '/'));
                $t->true($f[0] !== 404, "$label: route ada (bukan 404)");
                if ($state === 'exists') {
                    $t->eq($f[0], 200, "$label: unduh 200");
                    $sha = $t->probe(function () use ($path) {
                        return sha1(\Illuminate\Support\Facades\Storage::get($path));
                    });
                    $t->eq(sha1($f[1]), $sha, "$label: isi unduhan = berkas di disk");
                } elseif ($state === 'default-thumbnail') {
                    $t->eq($f[0], 200, "$label: unduh 200");
                    $t->eq(sha1($f[1]), $thumbSha, "$label: isi = public/images/thumbnail.png (thumbnail bawaan)");
                } else {
                    // kode V4 & V5 sama: ErrorMessageException('NOT_FOUND_ATTACHMENT') status bawaan 500 (pra-ada, bukan item ini)
                    $t->eq($f[0], 500, "$label: berkas $state -> 500 seperti V4");
                    $t->true(strpos($f[1], 'NOT_FOUND_ATTACHMENT') !== false, "$label: kode NOT_FOUND_ATTACHMENT");
                }
                $notes[] = "$label=" . $f[0] . "($state," . strlen($f[1]) . 'B)';
            };

            // 1. logo perusahaan (detail) + Company::info() (PDF/laporan)
            $companies = $db->select('select c.id_company, a.id_attachment from companies c left join attachments a on a.id_transaction = c.id_company');
            foreach (array_slice($companies, 0, 3) as $c) {
                $r = $t->call($s, 'GET', 'api/v5/company/companies/' . rawurlencode($c->id_company));
                $t->status($r, 200, 'GET company/companies/{id}');
                $urls = $collect($r[1]['result'] ?? []);
                if ($c->id_attachment === null) {
                    $t->eq($urls, [], 'perusahaan tanpa logo: tanpa URL unduhan');
                    continue;
                }
                $t->eq(count($urls), 1, 'perusahaan ber-logo: 1 URL (logoSmallUrl)');
                $check('logo ' . substr($c->id_company, -4), $urls[0]);
            }
            $info = $t->probe(function () use ($qaDb) {
                \Illuminate\Support\Facades\DB::setDefaultConnection('qa_ro_' . $qaDb);
                $d = \Modules\V5\Entities\Models\Company::info();

                return $d ? $d->logo_url : null;
            });
            if ($info !== null && strpos((string) $info, 'files/download') !== false) {
                $check('Company::info logo_url', $info);
            } else {
                $notes[] = 'Company::info logo_url=' . var_export($info, true);
            }

            // 2. thumbnail barang (append mainPhoto) di select item Issue/Receipt
            $r = $t->call($s, 'GET', 'api/v5/select/inventory/issue-receipt/items');
            $t->status($r, 200, 'GET select/inventory/issue-receipt/items');
            $urls = array_values(array_unique($collect($r[1]['result'] ?? [])));
            $t->true(count($urls) > 0, 'select item: ada URL thumbnail (' . count($urls) . ' unik)');
            foreach (array_slice($urls, 0, 3) as $i => $u) {
                $check('item mainPhoto #' . $i, $u);
            }

            // 3. item request detail (main_photo.thumbnail_path)
            $irq = $db->selectOne('select id_item_request from item_requests order by created_at desc limit 1');
            if ($irq) {
                $r = $t->call($s, 'GET', 'api/v5/inventory/item-requests/' . rawurlencode($irq->id_item_request));
                $t->status($r, 200, 'GET inventory/item-requests/{irq}');
                $urls = array_values(array_unique($collect($r[1]['result'] ?? [])));
                foreach (array_slice($urls, 0, 2) as $i => $u) {
                    $check('item-request thumbnail #' . $i, $u);
                }
                $notes[] = 'item-request URL unik=' . count($urls);
            }

            // 4. lampiran yang ADA di disk: URL v5 hasil route() mengunduh isi yang sama; URL v4 lama kini 404
            $cands = $db->select('select id_attachment, file_path from attachments order by created_at desc limit 200');
            $found = null;
            foreach ($cands as $a) {
                $ok = $t->probe(function () use ($a) {
                    return \Illuminate\Support\Facades\Storage::exists($a->file_path);
                });
                if ($ok) {
                    $found = $a;
                    break;
                }
            }
            if ($found) {
                $url = $t->probe(function () use ($found) {
                    return route('v5.select.files.download', ['idAttachment' => $found->id_attachment], false);
                });
                $check('lampiran ada di disk', $url);
                $old = $t->callFile(null, 'GET', 'api/v4/select/files/download?idAttachment=' . rawurlencode($found->id_attachment));
                $t->eq($old[0], 404, 'URL v4 lama sesudah V4 dimatikan = 404');
            } else {
                $notes[] = 'tidak ada lampiran yang berkasnya ada di disk (200 terakhir)';
            }

            // 5. lampiran Raptor (deposit/invoice): presenter in-process (raptor_* 0 baris di DB ini), URL lalu diunduh
            $fake = (object) ['id_attachment' => 'QA-NOFILE', 'file_name' => 'qa.png', 'file_path' => 'attachments/qa-ed1070-nofile.png', 'description' => null];
            foreach (['RaptorDeposit', 'RaptorInvoice'] as $m) {
                $p = $t->probe(function () use ($m, $fake) {
                    $cls = '\\Modules\\V5\\Http\\Services\\Raptor\\' . $m . '\\Attachment\\AttachmentPresenter';

                    return $cls::present($fake);
                });
                $t->eq($p['url'] ?? null, $v5 . 'idAttachment=QA-NOFILE', "$m AttachmentPresenter url");
                $f = $t->callFile(null, 'GET', ltrim($p['url'], '/'));
                $t->eq($f[0], 500, "$m url id tak ada -> 500 NOT_FOUND_ATTACHMENT seperti V4");
                $t->true(strpos($f[1], 'NOT_FOUND_ATTACHMENT') !== false, "$m: kode NOT_FOUND_ATTACHMENT");
            }

            // 6. template email OTP: {{ route() }} di dalam komentar HTML tetap dieksekusi Blade
            $html = $t->probe(function () {
                $path = base_path('Modules/V5/Resources/views/salesman-activity/email-template/otp-request.blade.php');
                $php = app('blade.compiler')->compileString(file_get_contents($path));
                $company = (object) ['company_name' => 'QA', 'logo_small_url' => 'x', 'id_attachment' => 'QA-OTP'];
                $data = ['customer' => ['customer_name' => 'QA'], 'otp_code' => '000000'];
                $__env = app('view');
                ob_start();
                try {
                    eval('?>' . $php);
                } catch (\Throwable $e) {
                    ob_end_clean();

                    return 'ERR ' . get_class($e) . ': ' . $e->getMessage();
                }

                return ob_get_clean();
            });
            $t->true(strpos($html, $v5 . 'idAttachment=QA-OTP') !== false, 'template OTP dirender, URL logo v5 (' . substr($html, 0, 60) . ')');

            // 7. foto check-in absensi (AttendanceService::show): dicatat saja - with('attachment') tanpa relasi di model
            //    Attendance = 500 pra-ada SEBELUM baris route() (bukan D-3); bila 200, URL dicek
            $att = $db->selectOne('select id_attendance from attendances order by created_at desc limit 1');
            if ($att) {
                $r = $t->call($s, 'GET', 'api/v5/salesman-activity/attendances/' . rawurlencode($att->id_attendance));
                $urls = $r[0] === 200 ? array_values(array_unique($collect($r[1]['result'] ?? []))) : [];
                foreach (array_slice($urls, 0, 1) as $u) {
                    $check('attendance photo_check_in', $u);
                }
                $notes[] = 'attendance show=' . $r[0] . ($r[0] >= 500 ? ' (' . substr((string) ($r[1]['message'] ?? ''), 0, 90) . ')' : '');
            }

            $t->note(implode(' ; ', $notes));
        },
    ],
];
