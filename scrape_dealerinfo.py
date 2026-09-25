"""Haalt voertuigprijzen per kleur op uit dealerinfo.net (@dios / Moteo).

Gebruik:
    pip install requests beautifulsoup4
    DEALERINFO_LOGIN=... DEALERINFO_PASSWORD=... python3 scrape_dealerinfo.py

Schrijft prijzen.csv en PRIJZEN.md. De catalogusprijzen zijn klantprijzen
incl. btw en excl. rijklaarkosten; RIJKLAAR wordt erbij opgeteld.
"""
import csv
import datetime
import os
import re

import requests
from bs4 import BeautifulSoup

BASE = "https://www.dealerinfo.net/"
RIJKLAAR = 250
# (index van de merkknop in het topmenu, merknaam)
BRANDS = [(0, "Peugeot"), (1, "SYM"), (3, "Yadea")]
# Categorieën zonder voertuigen (losse accu's e.d.)
SKIP_CATEGORIES = {
    "BATTERIJEN", "E-XPRO", "E-FIDDLE / E-MIO / E-XPRO NEW",
    "C1S/G5/M6(PLUS)", "Y1S", "TROOPER01", "OWIN/VELAX",
}
# Kleuren die niet netjes uit de omschrijving te halen zijn
COLOR_OVERRIDES = {
    "FIG4SL-M5Y2": "Y2 (kleurcode, naam niet vermeld)",
    "J4RX25-M5PGN5477S-BK": "Night Purple/Black",
    "J4RX-M5PGN5477S-BK00": "Night Purple/Black",
    "FUG12A-M4BK502C-S352": "Black/Silver",
    "FUG12A-M4GY218-BK502": "Black/Grey",
    "ECHS12A-M6GN553U-BK4": "Cyan Green/Mat Black",
    "JETX12-M5BK315U": "Mat Black",
    "CRU12-M4GY009C": "Rock Ash",
    "ADX30-M4GY7547UL": "Blue/Submarine Grey",
}
MODEL_RE = r"(E5\+|E5P|M\d|KM/H|KM|45|EVO|TCS|KEYLS|KEYLESS|1BATT M\d|CC|ABS)"


def hidden_fields(html):
    soup = BeautifulSoup(html, "html.parser")
    return {i["name"]: i.get("value", "") for i in soup.select("input[type=hidden]") if i.get("name")}


def login(session):
    r = session.get(BASE + "Login.aspx")
    data = hidden_fields(r.text)
    data.update(
        txtLogin=os.environ["DEALERINFO_LOGIN"],
        txtPassword=os.environ["DEALERINFO_PASSWORD"],
        btnLogin="OK",
        lstLanguages="NL",
    )
    r = session.post(BASE + "Login.aspx", data=data)
    if "Login.aspx" in r.url:
        raise SystemExit("Inloggen mislukt")


def select_brand(session, idx):
    r = session.get(BASE + "Pages/NewsSelection.aspx")
    data = hidden_fields(r.text)
    button = f"ctl00$ctl00$topMenu$rptBrands$ctl0{idx}$btnBrand"
    data[button + ".x"] = data[button + ".y"] = "10"
    return session.post(r.url, data=data).text


def color_of(art, desc):
    if art in COLOR_OVERRIDES:
        return COLOR_OVERRIDES[art]
    c = re.sub(r"\s*\([A-Z0-9-]+\)$", "", desc).strip()
    c = re.sub(r".*?" + MODEL_RE + r"\s+(?=[A-Z/.]+( [A-Z/.]+)*$)", "", c).title()
    edition = re.search(r"BLACK EDITION|SHADOW|SPORT", desc)
    return f"{c} ({edition[0].title()})" if edition else c


def price_of(text):
    return float(text.replace("€", "").replace(".", "").replace(",", ".").strip())


def scrape():
    session = requests.Session()
    session.headers["User-Agent"] = "Mozilla/5.0"
    login(session)
    rows = []
    for idx, brand in BRANDS:
        soup = BeautifulSoup(select_brand(session, idx), "html.parser")
        links = soup.find_all("a", href=re.compile(r"CategoryDetail\.aspx\?brand=\d+&catalog=1&"))
        for a in links:
            category = " ".join(a.get_text(" ").split())
            if category in SKIP_CATEGORIES:
                continue
            page = BeautifulSoup(session.get(BASE + "Pages/" + a["href"]).text, "html.parser")
            for tr in page.select('tr.clickable[onclick*="PartDetail"]'):
                art = tr.select_one("[id*=lblArtNr_]").get_text(strip=True)
                desc = tr.select_one("[id*=lblDescription_]").get_text(" ", strip=True)
                avail = tr.select_one("[id*=lblAvailability_]")
                price = price_of(tr.select_one("td.align-right").get_text(" ", strip=True))
                rows.append({
                    "merk": brand,
                    "model": category,
                    "kleur": color_of(art, desc),
                    "prijs_excl_rijklaar": price,
                    "prijs_incl_rijklaar": price + RIJKLAAR,
                    "artikelnummer": art,
                    "omschrijving": desc,
                    "levering": avail.get_text(" ", strip=True) if avail else "",
                })
    return rows


def eur(n):
    s = f"{n:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    return "€ " + s.removesuffix(",00")


def write(rows):
    with open("prijzen.csv", "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]), delimiter=";")
        w.writeheader()
        for r in rows:
            w.writerow({**r, **{k: f"{r[k]:.2f}".replace(".", ",") for k in ("prijs_excl_rijklaar", "prijs_incl_rijklaar")}})
    today = datetime.date.today().strftime("%d-%m-%Y")
    out = [
        "# Prijzen Peugeot, SYM & Yadea per kleur", "",
        f"Bron: dealerinfo.net (@dios, klantprijzen incl. btw), opgehaald {today}.", "",
        f"- **Excl. rijklaar** = catalogusprijs dealerinfo.net.",
        f"- **Incl. rijklaar** = catalogusprijs + {eur(RIJKLAAR)} rijklaarkosten.",
        "- **Levering** = beschikbaarheid bij de importeur op het moment van ophalen.",
    ]
    brand = model = None
    for r in rows:
        if r["merk"] != brand:
            brand = r["merk"]
            out += ["", f"## {brand}"]
        if r["model"] != model:
            model = r["model"]
            out += ["", f"### {model}", "", "| Kleur | Excl. rijklaar | Incl. rijklaar | Artikelnr. | Levering |", "|---|--:|--:|---|---|"]
        out.append(f"| {r['kleur']} | {eur(r['prijs_excl_rijklaar'])} | {eur(r['prijs_incl_rijklaar'])} | `{r['artikelnummer']}` | {r['levering']} |")
    with open("PRIJZEN.md", "w") as f:
        f.write("\n".join(out) + "\n")


if __name__ == "__main__":
    data = scrape()
    write(data)
    print(f"{len(data)} regels geschreven")
