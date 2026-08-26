<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Plugin JeeConsoAPI - Aldarande */

if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
sendVarToJS('eqType', 'jeeconsoapi');
$eqLogics = eqLogic::byType('jeeconsoapi');
?>

<div class="row row-overflow">

    <!-- ============================================================
         PAGE LISTE
    ============================================================ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">

        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i><br><span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-table"></i> {{Mes compteurs}}</legend>

        <?php if (count($eqLogics) == 0) : ?>
        <div class="text-center" style="margin-top:20px;color:#888;font-size:1.1em;">
            {{Aucun compteur — cliquez sur « Ajouter » pour commencer}}
        </div>
        <?php else : ?>

        <div class="input-group" style="margin:5px;">
            <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
            <div class="input-group-btn">
                <a id="bt_resetSearch" class="btn" style="width:30px;"><i class="fas fa-times"></i></a>
                <a class="btn roundedRight" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0">
                    <i class="fas fa-th-large"></i>
                </a>
            </div>
        </div>

        <div class="eqLogicThumbnailContainer">
            <?php foreach ($eqLogics as $eqLogic) :
                $opacity  = $eqLogic->getIsEnable() ? '' : 'disableCard';
                $doneDate = $eqLogic->getConfiguration('state_done_date', '');
            ?>
            <div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>"
                 data-eqlogic_id="<?php echo $eqLogic->getId(); ?>">
                <img src="plugins/jeeconsoapi/plugin_info/icon.png"
                     alt=""
                     style="width:65px;height:65px;object-fit:contain;margin-bottom:4px;"/>
                <br>
                <span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
                <?php if ($doneDate !== '') : ?>
                <span style="display:block;font-size:0.75em;color:#888;font-weight:normal;">
                    <?php echo htmlspecialchars($doneDate, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php endif; ?>
                <span class="hiddenAsCard displayTableRight hidden">
                    <?php echo $eqLogic->getIsVisible()
                        ? '<i class="fas fa-eye"></i>'
                        : '<i class="fas fa-eye-slash"></i>'; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div><!-- /.eqLogicThumbnailDisplay -->

    <!-- ============================================================
         PAGE ÉQUIPEMENT
    ============================================================ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure">
                    <i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a><a
                   class="btn btn-sm btn-default eqLogicAction" data-action="copy">
                    <i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a><a
                   class="btn btn-sm btn-success eqLogicAction" data-action="save">
                    <i class="fas fa-check-circle"></i> {{Sauvegarder}}</a><a
                   class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove">
                    <i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" role="tab" data-toggle="tab"
                   data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#jeeconsoapi_tab_eq" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Compteur}}
                </a>
            </li>
            <li role="presentation">
                <a href="#jeeconsoapi_tab_cmd" role="tab" data-toggle="tab">
                    <i class="fas fa-list"></i> {{Commandes}}
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ================================================
                 ONGLET COMPTEUR
            ================================================ -->
            <div role="tabpanel" class="tab-pane active" id="jeeconsoapi_tab_eq">
                <form class="form-horizontal">
                <fieldset>
                <div class="row">

                    <!-- ---------- COLONNE GAUCHE ---------- -->
                    <div class="col-lg-6">

                        <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Nom du compteur}}</label>
                            <div class="col-sm-7">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="id" style="display:none;">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="name" placeholder="{{Nom du compteur}}">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Objet parent}}</label>
                            <div class="col-sm-7">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php foreach (jeeObject::buildTree(null, false) as $object) : ?>
                                    <option value="<?php echo $object->getId(); ?>">
                                        <?php echo str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')); ?>
                                        <?php echo $object->getName(); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Catégorie}}</label>
                            <div class="col-sm-7">
                                <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) : ?>
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr"
                                           data-l1key="category"
                                           data-l2key="<?php echo $key; ?>">
                                    <?php echo $value['name']; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Options}}</label>
                            <div class="col-sm-7">
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr"
                                           data-l1key="isEnable" checked> {{Activer}}
                                </label>
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr"
                                           data-l1key="isVisible" checked> {{Visible}}
                                </label>
                            </div>
                        </div>

                        <legend><i class="fas fa-key"></i> {{Accès aux données Enedis}}</legend>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">
                                {{PRM}}
                                <sup><i class="fas fa-question-circle tooltips"
                                        title="{{Identifiant à 14 chiffres de votre point de livraison. Il figure sur votre facture d'électricité et sur l'écran du compteur Linky (touche + jusqu'à « Numéro de PRM »).}}"></i></sup>
                            </label>
                            <div class="col-sm-7">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="prm"
                                       id="jeeconsoapi_prm"
                                       inputmode="numeric" maxlength="14"
                                       placeholder="{{14 chiffres}}"/>
                                <span class="help-block" style="margin-bottom:0;font-size:0.85em;">
                                    {{Exactement 14 chiffres, sans espace.}}
                                </span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-4 control-label">
                                {{Token Bearer}}
                                <sup><i class="fas fa-question-circle tooltips"
                                        title="{{Token personnel obtenu après avoir donné votre consentement Enedis sur conso.boris.sh. Il est chiffré avant d'être stocké et n'est jamais réaffiché ici.}}"></i></sup>
                            </label>
                            <div class="col-sm-7">
                                <input type="password" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="token"
                                       id="jeeconsoapi_token"
                                       autocomplete="new-password" spellcheck="false"
                                       placeholder="xxx.yyy.zzz"/>
                                <span class="help-block" id="jeeconsoapi_tokenState"
                                      style="margin-bottom:0;font-size:0.85em;"></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-7 col-sm-offset-4">
                                <a href="https://conso.boris.sh/api/auth" target="_blank"
                                   rel="noopener noreferrer" class="btn btn-default btn-sm">
                                    <i class="fas fa-external-link-alt"></i> {{Obtenir mon token}}
                                </a>
                                <span class="help-block" style="font-size:0.85em;">
                                    {{Ouvre le consentement Enedis sur conso.boris.sh. Copiez le token obtenu dans le champ ci-dessus, puis sauvegardez.}}
                                </span>
                            </div>
                        </div>

                    </div><!-- /col-lg-6 -->

                    <!-- ---------- COLONNE DROITE ---------- -->
                    <div class="col-lg-6">

                        <legend><i class="fas fa-plug"></i> {{Vérification}}</legend>

                        <div class="alert alert-info" style="font-size:0.9em;">
                            {{Conso API demande un seul cycle d'appel par jour et par compteur.
                            Chaque bouton de cette colonne déclenche une requête réelle et
                            consomme une des tentatives quotidiennes autorisées : à utiliser
                            pour vérifier votre configuration, pas de façon répétée.}}
                        </div>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <a class="btn btn-default" id="bt_jeeconsoapiTest">
                                    <i class="fas fa-vial"></i> {{Tester la connexion}}
                                </a>
                                <a class="btn btn-default" id="bt_jeeconsoapiRefresh">
                                    <i class="fas fa-sync-alt"></i> {{Actualiser maintenant}}
                                </a>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <div id="jeeconsoapi_testResult"></div>
                            </div>
                        </div>

                        <legend><i class="fas fa-history"></i> {{Importer l'historique}}</legend>

                        <div class="form-group">
                            <label class="col-sm-5 control-label">{{Profondeur}}</label>
                            <div class="col-sm-4">
                                <select class="form-control" id="jeeconsoapi_backfillMonths">
                                    <option value="1">{{1 mois}}</option>
                                    <option value="6">{{6 mois}}</option>
                                    <option value="12" selected>{{12 mois}}</option>
                                    <option value="36">{{36 mois}}</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <a class="btn btn-warning" id="bt_jeeconsoapiBackfill">
                                    <i class="fas fa-download"></i> {{Importer l'historique}}
                                </a>
                                <span class="help-block" style="font-size:0.85em;">
                                    {{Rapatrie les mesures passées dans l'historique Jeedom.
                                    Deux requêtes au total, quelle que soit la profondeur.
                                    Relancer l'import n'écrit jamais de doublon.}}
                                </span>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <div id="jeeconsoapi_backfillResult"></div>
                            </div>
                        </div>

                        <legend><i class="fas fa-clock"></i> {{Planification}}</legend>

                        <div class="form-group">
                            <div class="col-sm-12">
                                <div id="jeeconsoapi_schedule"
                                     style="font-size:0.9em;line-height:1.9;"></div>
                            </div>
                        </div>

                    </div><!-- /col-lg-6 -->

                </div>
                </fieldset>
                </form>
            </div><!-- /#jeeconsoapi_tab_eq -->

            <!-- ================================================
                 ONGLET COMMANDES
            ================================================ -->
            <div role="tabpanel" class="tab-pane" id="jeeconsoapi_tab_cmd">
                <br>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th style="width:60px;">{{ID}}</th>
                                <th style="width:260px;">{{Nom}}</th>
                                <th style="width:70px;">{{Unité}}</th>
                                <th>{{Valeur}}</th>
                                <th style="width:190px;">{{Options}}</th>
                                <th style="width:150px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div><!-- /#jeeconsoapi_tab_cmd -->

        </div><!-- /.tab-content -->
    </div><!-- /.eqLogic -->
</div><!-- /.row -->

<?php include_file('desktop', 'jeeconsoapi', 'js', 'jeeconsoapi'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
