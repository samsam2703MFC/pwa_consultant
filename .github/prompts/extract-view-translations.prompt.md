# Uzupełnij tłumaczenia dla widoku

Przeanalizuj wskazany widok i przygotuj brakujące tłumaczenia zgodnie ze strukturą projektu.

## Cel
- znajdź wszystkie stringi dodane bezpośrednio w widoku lub w nowo dodanym kodzie widoku
- wskaż, które z nich powinny zostać wyciągnięte do tłumaczeń
- określ właściwy plik `module.json` dla każdego tłumaczenia
- przygotuj tłumaczenia dla języków: `en`, `fr`, `it`, `pl`, `nl`
- zachowaj istniejącą strukturę kluczy i styl tłumaczeń stosowany w danym projekcie
- nie zmieniaj istniejącej organizacji katalogów tłumaczeń

## Reguła wyboru pliku tłumaczeń
Nazwa pliku `module.json` odpowiada najwyższemu katalogowi wewnątrz `views`.

Przykład:
- jeśli widok znajduje się w `views/product/category/manage/new.twig`
- to tłumaczenia należy umieścić w pliku `product.json`
- we wszystkich katalogach językowych: `en`, `fr`, `it`, `pl`, `nl`

## Mapowanie projektów na ścieżki tłumaczeń
- `admin` → `public/resources/lang/page/{lang}/module.json`
- `employee` → `src/core/I18n/translations/page/{lang}/module.json`
- `kitchen` → `src/core/I18n/translations/page/{lang}/module.json`
- `owner` → `src/core/I18n/translations/page/{lang}/module.json`
- `panel` → `public/resources/lang/page/{lang}/module.json`
- `supplier` → `src/core/I18n/translations/page/{lang}/module.json`
- `consultant` → `src/core/I18n/translations/page/{lang}/module.json`

gdzie `{lang}` to jeden z:
- `en`
- `fr`
- `it`
- `pl`
- `nl`

## Sposób pracy
1. Na podstawie ścieżki pliku ustal projekt docelowy.
2. Na podstawie ścieżki widoku ustal nazwę pliku `module.json`.
3. Przeanalizuj kod widoku i znajdź wszystkie stringi widoczne dla użytkownika.
4. Odróżnij:
   - teksty wymagające tłumaczenia,
   - identyfikatory techniczne, klasy CSS, nazwy route, klucze danych i wartości nietłumaczalne.
5. Sprawdź istniejący plik tłumaczeń i dopasuj strukturę kluczy do obecnej konwencji.
6. Jeśli odpowiednie klucze już istnieją, użyj ich zamiast tworzyć duplikaty.
7. Jeśli trzeba dodać nowe klucze, zachowaj spójne nazewnictwo z istniejącą strukturą.
8. Przygotuj tłumaczenia dla wszystkich wymaganych języków.
9. Jeśli znaczenie tekstu jest niejednoznaczne, nie zgaduj — wskaż to i poproś o doprecyzowanie.
10. Tłumaczenie zawsze powinno mieć struktuę: (dla plików .twig) translations.key lub (dla plików .view.php) $translations['key'].

## Oczekiwany wynik
Najpierw przedstaw:
1. listę stringów do wyciągnięcia,
2. docelowy plik tłumaczeń,
3. proponowane klucze tłumaczeń,
4. informację, czy odpowiednie wpisy już istnieją.

Dopiero po akceptacji:
5. przygotuj finalne zmiany w plikach tłumaczeń i ewentualną aktualizację widoku tak, aby korzystał z kluczy tłumaczeń zamiast literalnych stringów.

## Uwagi
- Traktuj tylko teksty widoczne dla użytkownika jako kandydatów do tłumaczeń.
- Nie tłumacz nazw technicznych, nazw pól backendowych ani wewnętrznych identyfikatorów bez wyraźnej potrzeby.
- Zachowuj styl językowy i terminologię istniejącą już w projekcie.