#!/usr/bin/env bash
# Sitenin ön yüzünü (public/ klasörü) php/ klasörüne kopyalar: index.html → app.html, diğer dosyalar olduğu gibi.
# Node sürümündeki tasarım değişince bunu çalıştır; php/ klasörünü olduğu gibi barındırmaya yükleyebilirsin.
set -euo pipefail
cd "$(dirname "$0")"
for f in ../public/*; do
  b=$(basename "$f")
  if [ "$b" = "index.html" ]; then cp "$f" app.html; else cp -r "$f" "./$b"; fi
done
cp ../games.json ./games.json 2>/dev/null || true   # her zaman sitede olacak oyunlar listesi
echo "ön yüz kopyalandı: $(ls | wc -l) öğe"
