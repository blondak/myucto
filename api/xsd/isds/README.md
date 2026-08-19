# XSD odesílací brány ISDS

`SetConcept.xsd` — schéma vstupu a výstupu webových služeb `SetConcept`,
`SetMultipleConcept` a `SetBigConcept`.

**Původ:** Technická příloha 2 Provozního řádu ISDS ve verzi k 26. 6. 2026,
soubor `pril_2/SetConcept.xsd` z balíku
<https://mojedatovaschranka.cz/info/files/2256_Provozni_rad_ISDS_26_06_2026.zip>
(rozcestník: <https://mojedatovaschranka.cz/info/cs/62.html>). Beze změny.

**K čemu tu je:** `SetConceptRequestWriter` proti němu validuje
`tests/Unit/Submission/SetConceptRequestWriterTest.php`. Není to formalita —
schéma deklaruje všechny prvky obálky jako **povinné a `nillable`**, takže
prázdnou hodnotu nelze vynechat ani zapsat jako prázdný element; jediná
prokazatelně správná podoba je `xsi:nil="true"`. Test hlídá právě tohle, protože
běžné serializéry prázdné prvky vynechávají a výsledek proti tomuhle schématu
neprojde.

**Pozor:** schéma je přísnější než reálná praxe ISDS (oficiální příručka sama
uvádí příklad, který proti němu nevaliduje). Chyba směrem „my jsme přísnější než
služba" je ale ten bezpečnější směr.
