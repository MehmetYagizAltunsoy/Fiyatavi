// Ortak listeler ve yönetim istatistikleri: gerçek sunucuyu geçici veri tabanıyla başlatıp HTTP üzerinden dener
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { DatabaseSync } from "node:sqlite";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { geciciVeriTabani } from "./yardimci.js";

const klasor = geciciVeriTabani("liste");
process.env.ADMIN_EMAILS = "ali@test.com";
process.env.ITAD_API_KEY = "x";
// Gerçek Express varsa sunucu ayrı süreçte başlatılıp HTTP ile denenir; sahte (çevrimdışı) Express varsa istekler doğrudan işleyicilere verilir
const express = (await import("express")).default;
const SAHTE = typeof express().routes !== "undefined";
const PORT = 3900 + Math.floor(Math.random() * 90);
const BASE = `http://127.0.0.1:${PORT}`;
const kok = path.join(path.dirname(fileURLToPath(import.meta.url)), "..");
let sunucu, db;

class Kisi {
  constructor() { this.cerez = ""; this.kullanici = null; }
  async istek(yol, yontem = "GET", govde) {
    if (SAHTE) {
      const o = await globalThis.__call(yontem, yol, govde, this.kullanici);
      if (o.body?.user?.email) this.kullanici = db.prepare("SELECT * FROM users WHERE email = ?").get(o.body.user.email);
      return { durum: o.status, veri: o.body ?? null };
    }
    const r = await fetch(BASE + yol, { method: yontem, headers: { "content-type": "application/json", cookie: this.cerez }, body: govde ? JSON.stringify(govde) : undefined });
    const c = r.headers.get("set-cookie"); if (c) this.cerez = c.split(";")[0];
    return { durum: r.status, veri: await r.json().catch(() => null) };
  }
}
const kayit = async (ad) => {
  const k = new Kisi();
  const r = await k.istek("/api/auth/register", "POST", { email: `${ad}@test.com`, password: "gizli123", name: ad, consent: true });
  assert.equal(r.durum, 200);
  return k;
};

before(async () => {
  if (SAHTE) {
    process.env.PORT = String(PORT);
    await import("../src/server.js");
    db = (await import("../src/db.js")).db;
  } else {
    sunucu = spawn(process.execPath, ["--no-warnings", "src/server.js"], { cwd: kok, env: { ...process.env, PORT: String(PORT), NODE_ENV: "development", SITE_URL: BASE }, stdio: "ignore" });
    for (let i = 0; i < 80; i++) { try { if ((await fetch(BASE + "/api/health")).ok) break; } catch {} await new Promise((r) => setTimeout(r, 250)); }
    db = new DatabaseSync(process.env.DB_PATH);
  }
  for (const [id, ad] of [["g1", "Oyun Bir"], ["g2", "Oyun İki"]]) db.prepare("INSERT OR IGNORE INTO games (id, title, hidden) VALUES (?, ?, 0)").run(id, ad);
});
after(() => { sunucu?.kill(); if (!SAHTE) db?.close(); setTimeout(() => process.exit(0), 100).unref(); });

test("liste kurulur, davetle katılınır, eklenen oyun herkesin istek listesine düşer", async () => {
  const ali = await kayit("ali"), veli = await kayit("veli");
  const o = await ali.istek("/api/lists", "POST", { name: "Hafta sonu" });
  assert.equal(o.durum, 200);
  const { id, token } = o.veri.list;
  assert.equal(o.veri.list.members.length, 1);
  assert.equal(o.veri.list.isOwner, true);

  const onizleme = await new Kisi().istek(`/api/lists/join/${token}`);
  assert.equal(onizleme.durum, 200);
  assert.equal(onizleme.veri.list.name, "Hafta sonu");
  assert.equal(onizleme.veri.list.joined, false);

  assert.equal((await ali.istek(`/api/lists/${id}/games`, "POST", { gameId: "g1" })).durum, 200);
  const kat = await veli.istek(`/api/lists/join/${token}`, "POST");
  assert.equal(kat.durum, 200);
  assert.equal(kat.veri.list.members.length, 2);
  assert.deepEqual((await veli.istek("/api/wishlist")).veri.ids, ["g1"], "katılan, listedeki oyunu istek listesinde bulur");

  await veli.istek(`/api/lists/${id}/games`, "POST", { gameId: "g2" });
  assert.deepEqual((await ali.istek("/api/wishlist")).veri.ids.sort(), ["g1", "g2"], "veli'nin eklediği oyun ali'ye de düşer");
  assert.equal((await ali.istek(`/api/lists/${id}`)).veri.list.games.length, 2);

  await ali.istek(`/api/lists/${id}/games/g2`, "DELETE");
  assert.equal((await veli.istek(`/api/lists/${id}`)).veri.list.games.length, 1);
});

test("yabancı listeye erişilemez, sahibi çıkınca liste devredilir, son üye çıkınca silinir", async () => {
  const ayse = await kayit("ayse"), can = await kayit("can"), dis = await kayit("dis");
  const { id, token } = (await ayse.istek("/api/lists", "POST", { name: "Ortak" })).veri.list;
  await can.istek(`/api/lists/join/${token}`, "POST");
  assert.equal((await dis.istek(`/api/lists/${id}`)).durum, 404);
  assert.equal((await dis.istek(`/api/lists/${id}/games`, "POST", { gameId: "g1" })).durum, 404);
  assert.equal((await can.istek(`/api/lists/${id}`, "DELETE")).durum, 403, "sahibi olmayan silemez");

  await ayse.istek(`/api/lists/${id}/leave`, "POST");
  const kalan = (await can.istek(`/api/lists/${id}`)).veri.list;
  assert.equal(kalan.owner, "can", "ayrılan sahibin yerine en eski üye geçer");
  await can.istek(`/api/lists/${id}/leave`, "POST");
  assert.equal(db.prepare("SELECT COUNT(*) AS n FROM lists WHERE id = ?").get(id).n, 0);
});

test("sınırlar: boş ad, geçersiz kod, aynı üyenin tekrar katılması", async () => {
  const k = await kayit("sinir");
  assert.equal((await k.istek("/api/lists", "POST", { name: "  " })).durum, 400);
  assert.equal((await new Kisi().istek("/api/lists/join/yanlis")).durum, 404);
  assert.equal((await new Kisi().istek("/api/lists")).durum, 401);
  const { token } = (await k.istek("/api/lists", "POST", { name: "Bir" })).veri.list;
  const tekrar = await k.istek(`/api/lists/join/${token}`, "POST");
  assert.equal(tekrar.veri.list.members.length, 1, "kendi listesine tekrar katılınca çoğalmaz");
  assert.equal((await k.istek("/api/lists")).veri.lists.length, 1);
});

test("yönetim istatistiklerinde son 14 günün günlük serisi var", async () => {
  const ali = new Kisi();
  const g = await ali.istek("/api/auth/login", "POST", { email: "ali@test.com", password: "gizli123" });
  assert.equal(g.durum, 200);
  const r = await ali.istek("/api/admin/stats");
  assert.equal(r.durum, 200);
  assert.equal(r.veri.daily.length, 14);
  assert.ok(r.veri.daily.at(-1).users >= 3, "bugünün üye sayısı artmış olmalı");
});
