<div align="center">
  <a href="README-TR.md">TR <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/TR.png" alt="TR" height="20" /></a>
  <a href="README.md"> | EN <img style="padding-top: 8px" src="https://raw.githubusercontent.com/yammadev/flag-icons/master/png/US.png" alt="EN" height="20" /></a>
</div>

<div align="center">

# WHMCS için DNA SSL

**Domain Name API SSL sertifikalarını WHMCS üzerinden satın.**

Domain Name API bayi hesabınızdaki DV, OV, EV, wildcard ve çoklu alan adı sertifikaları:
aktivasyonda sipariş edilir, müşteri panelinden doğrulanır ve teslim edilir.

![WHMCS](https://img.shields.io/badge/WHMCS-8.0%20%E2%80%93%209.x-4A90D9?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.4-777BB4?style=flat-square&logo=php&logoColor=white)

</div>

> WISECP 5 mi kullanıyorsunuz? [wisecp-ssl-dna](https://github.com/domainreseller/wisecp-ssl-dna) reposuna bakın.

---

## 📦 İndirme

[Son sürümden](https://github.com/domainreseller/whmcs-ssl-dna/releases/latest) `DNASSL-WHMCS.zip`
dosyasını indirip WHMCS kök dizinine açın; `modules/servers/dnassl` klasörü oluşur.

## ✨ Neler yapar

- Hizmet aktive edildiğinde sertifika siparişini Domain Name API'de açar
- Üründeki **Certificate** listesini Domain Name API kataloğunuzdan, bayi maliyetinizle canlı doldurur
- Müşteri paneli: CSR yapıştırma ya da modüle oluşturtma (özel anahtar sonradan indirilebilir)
- E-posta, HTTP dosyası veya DNS (CNAME) doğrulama; yayınlanacak dosya / kayıt birebir gösterilir
- Paket limitine kadar ek alan adı (SAN)
- Müşteri panelinden sertifika indirme (sertifika + CA zinciri) ve yeniden düzenleme
- Yenileme yeni sipariş olarak açılır, kayıtlı CSR kullanılır; müşterinin bir şey yapması gerekmez
- Sonlandırmada isteğe bağlı iptal; yönetici için *Refresh Status* ve *Revoke Certificate* düğmeleri

## 📋 Gereksinimler

- SSL ürünleri tanımlı bir **Domain Name API bayi hesabı**, **Bayi ID** ve **API Anahtarı**
- WHMCS 8.0 veya üzeri
- `curl` ve `json` eklentili PHP 7.4 – 8.4 (Türkçe karakterli alan adları için `intl` önerilir)

## 🚀 Kurulum

1. `DNASSL-WHMCS.zip` dosyasını WHMCS kök dizinine açın.
2. **System Settings → Servers → Add New Server**

   | Alan | Değer |
   |---|---|
   | Name | istediğiniz bir ad, ör. `Domain Name API SSL` |
   | Hostname | boş bırakın |
   | Module | `DNA SSL Certificates` |
   | Username | **Bayi ID**'niz |
   | Password | **API Anahtarınız** — canlı ya da test |

   Modül, anahtarın canlı ortama mı test ortamına mı ait olduğunu kendisi bulur. Belirli bir
   ortamı zorlamak için hostname'e `api.domainresellerapi.com` (canlı) ya da
   `ote.domainresellerapi.com` (test) yazın.

   **Test Connection** ile deneyin, kaydedin ve sunucuyu bir sunucu grubuna ekleyin.
3. Bir ürün oluşturun (tür *Other*), **Module Settings** sekmesinde `DNA SSL Certificates`,
   sunucu grubunu ve **Certificate** alanını seçin. İsterseniz **Revoke on Termination**'ı işaretleyin.
4. Sipariş formunun **alan adı** istediğinden emin olun: sertifika bu alan adı için düzenlenir.

Ödemeden sonra sipariş Domain Name API'de açılır. Müşteri, müşteri panelinde hizmeti açar,
CSR yapıştırır ya da modüle oluşturtur, doğrulama yöntemini seçer ve sertifika düzenlenene
kadar orada gösterilen talimatları izler.

## 🧩 Bilinmesi gerekenler

- **Sertifika alan adıyla tanınır.** Domain Name API sertifikaları sipariş numarasıyla değil
  alan adıyla tanır; modül siparişin açıldığı alan adını saklar.
- **Ek alan adları siparişle birlikte alınır.** API mevcut bir sertifikaya SAN ekleyemez.
- **Doğrulama e-postası istek üzerine yeniden gönderilemez,** açık bir siparişte yöntem de
  değiştirilemez. Yeniden düzenleme (reissue) doğrulamayı baştan başlatır.
- **Doğrulama yöntemleri** e-posta, HTTP dosyası veya DNS CNAME kaydıdır; API'de HTTPS
  dosyası ya da TXT doğrulaması yoktur.
- **Web sunucusu tipi** *Apache* (nginx, LiteSpeed, cPanel, Plesk dahil) veya
  *Microsoft IIS* olabilir: API'nin kabul ettiği iki değer bunlar.
- **İletişim telefonunun ülke kodu en fazla iki haneli olabilir** (API sınırı). Üç haneli
  kodlu müşteriler (ör. +994, +380) API'nin doğrulama mesajını görür.
- **Onay e-postası** yalnızca Domain Name API'nin o alan adı için listelediği adresler ya da
  alan adının kendi posta kutularından biri olabilir.
- **Fiyatlar** bayi hesabınızın fiyat grubundan (Reseller, Premium, Platinum, VIP) alınır.
- **Bazı paketler iptal edilemez** (API "Package is not supported" der); sonlandırma yine
  tamamlanır ve deneme Activity Log'a yazılır.
- **Özel anahtar** yalnızca CSR'ı modül oluşturduysa ve WHMCS sistem anahtarıyla şifrelenerek
  saklanır. Hiçbir zaman log'a yazılmaz.

## 🔍 Loglar

- *Utilities → Logs → Module Log* (Module Debug Mode açıkken): tüm istek ve cevaplar.
- *Utilities → Logs → Activity Log*: her başarısız işlem için bir `dnassl:` satırı.

API anahtarı ve özel anahtarlar log'a yazılmadan önce `***` ile değiştirilir.

## 🛠 Geliştirme

```
modules/servers/dnassl/             modül
modules/servers/dnassl/lib/DnaSsl/  Domain Name API SSL istemcisi (PHP 7.4+)
tools/build-release-zip.sh [ref|WORKTREE] [çıktı-dizini]
tests/                              sahte API ile smoke testleri
```

```bash
php tests/smoke-shared.php
tools/build-release-zip.sh WORKTREE /tmp/dnassl && php tests/smoke-whmcs.php /tmp/dnassl/DNASSL-WHMCS.zip
```

`lib/DnaSsl`, [wisecp-ssl-dna](https://github.com/domainreseller/wisecp-ssl-dna) içindekiyle aynı
istemcidir; iki kopya birebir aynı tutulmalıdır.

## 📄 Değişiklik kaydı

[CHANGELOG.md](CHANGELOG.md) dosyasına bakın.

---

<div align="center">

**WHMCS için DNA SSL** · Domain Name API SSL sertifikaları

[domainnameapi.com](https://www.domainnameapi.com) · [Bayi paneli](https://dm.domainnameapi.com)

</div>
